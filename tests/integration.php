<?php

use craft\db\Query;
use craft\elements\Asset;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\fs\Local;
use craft\helpers\Db;
use craft\models\Volume;
use Noo\CraftBlurhash\console\controllers\GenerateController;
use Noo\CraftBlurhash\controllers\BlurhashController;
use Noo\CraftBlurhash\jobs\ComputeBlurhashJob;
use Noo\CraftBlurhash\migrations\Install;
use Noo\CraftBlurhash\migrations\m261119_000000_normalize_source_time;
use Noo\CraftBlurhash\models\BlurhashRecord;
use Noo\CraftBlurhash\Plugin;
use Noo\CraftBlurhash\services\BlurhashService;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

$base = getenv('CRAFT_BASE_PATH');
if (!preg_match('~^/tmp/craft-blurhash\.[a-zA-Z0-9]+$~D', (string)$base)
    || getenv('CRAFT_DB_DSN') !== "mysql:unix_socket=$base/db.sock;dbname=blurhash_test") {
    throw new RuntimeException('Use bash scripts/test-integration.sh (isolated database required).');
}
require dirname(__DIR__).'/vendor/autoload.php';
$mode = $argv[1];
if ($mode === 'utility') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = "$base/web/index.php";
    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/admin/utilities/blurhash';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
$type = $mode === 'utility' ? 'web' : 'console';
$app = require dirname(__DIR__)."/vendor/craftcms/cms/bootstrap/$type.php";
set_exception_handler(static function (Throwable $error) {
    fwrite(STDERR, "$error\n");
    exit(1);
});
if ($mode === 'install/craft') {
    exit($app->run());
}
$plugin = new Plugin('blurhash', $app);
$service = $plugin->blurhash;
$queue = $app->getQueue();

function check(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

function imageFile(int $color): string
{
    $file = tempnam(getenv('CRAFT_BASE_PATH'), 'source');
    $gd = imagecreatetruecolor(12, 10);
    imagefill($gd, 0, 0, $color);
    imagepng($gd, $file);
    return $file;
}

function drain(): void
{
    $q = Craft::$app->getQueue();
    $q->run();
    check($q->getTotalJobs() === 0, json_encode($q->getJobInfo(), JSON_THROW_ON_ERROR));
}

if ($mode === 'generate') {
    exit((new GenerateController('generate', $plugin))->actionIndex());
}

if ($mode === 'utility') {
    $controller = new BlurhashController('blurhash', $plugin);
    try {
        $controller->actionRegenerateAll();
        throw new RuntimeException('Regeneration accepted GET.');
    } catch (MethodNotAllowedHttpException) {
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        $controller->actionGenerateMissing();
        throw new RuntimeException('Generation accepted an anonymous user.');
    } catch (ForbiddenHttpException) {
    }
    $app->getUser()->setIdentity(User::find()->username('testadmin')->one());
    $before = BlurhashRecord::find()->one();
    $controller->actionRegenerateAll();
    check(BlurhashRecord::findOne($before->id)->blurhash === $before->blurhash, 'The CP wiped a live placeholder.');
    check($queue->getTotalJobs() === 1, 'The CP must enqueue only the dispatcher.');
    // Change the stored value without changing its mtime to prove force reaches the worker.
    BlurhashRecord::updateAll(['blurhash' => '000000'], ['id' => $before->id]);
    $queue->executeJob();
    $jobs = $queue->getJobInfo();
    check(count($jobs) === 1, 'Dispatcher did not schedule one batch: '.json_encode($jobs));
    $batch = $queue->getJobDetails($jobs[0]['id'])['job'];
    check($batch->force && (int)$batch->assetIds[0] === (int)$before->assetId, 'Force and asset ID did not reach the batch.');
    drain();
    $refreshed = BlurhashRecord::findOne($before->id);
    check($refreshed->blurhash === $before->blurhash, "Forced refresh: expected $before->blurhash; got $refreshed->blurhash");
    $controller->actionGenerateMissing();
    $queue->executeJob();
    check($queue->getTotalJobs() === 0, 'Generate Missing included a generated asset.');
    BlurhashRecord::deleteAll(['id' => $before->id]);
    $controller->actionGenerateMissing();
    drain();
    check(BlurhashRecord::find()->one()?->blurhash === $before->blurhash, 'Generate Missing did not compute the missing asset.');
    BlurhashRecord::deleteAll(['assetId' => $before->assetId]); // leave this asset for the console test
    echo "Utility action, permission and batch checks passed.\n";
    exit(0);
}

check($mode === 'assets', 'Unknown test mode.');
(new Install())->safeUp();
$fs = new Local(['name' => 'Images', 'handle' => 'images', 'path' => "$base/images"]);
check($app->getFs()->saveFilesystem($fs), json_encode($fs->getErrors()));
$volume = new Volume(['name' => 'Images', 'handle' => 'images', 'fsHandle' => 'images']);
check($app->getVolumes()->saveVolume($volume), json_encode($volume->getErrors()));
$asset = new Asset([
    'newFolderId' => $app->getAssets()->getRootFolderByVolumeId($volume->id)->id,
    'newFilename' => 'image.png', 'tempFilePath' => imageFile(0x123456),
    'volumeId' => $volume->id, 'scenario' => Asset::SCENARIO_CREATE,
]);
check($app->getElements()->saveElement($asset), json_encode($asset->getErrors()));
$counts = $service->getStats(missingLimit: 0);
check($counts === ['eligible' => 1, 'generated' => 0, 'missing' => 1, 'missingAssets' => []], 'Wrong count-only stats.');
check(count($service->getStats()['missingAssets']) === 1, 'Missing image not listed.');
check($service->getStats(300)['eligible'] === 0, 'Health check counted a recent upload.');
$jobCount = static fn() => (int)(new Query())->from('{{%queue}}')->where(['description' => "Computing blurhash for asset #{$asset->id}"])->count();
check($jobCount() === 1, 'Asset save did not enqueue a job.');
$asset->propagating = true;
$asset->trigger(Asset::EVENT_AFTER_SAVE, new ModelEvent());
$asset->propagating = false;
check($jobCount() === 1, 'Propagation duplicated work.');
$queue->releaseAll();
$app->getCache()->flush();
$plugin->getSettings()->computeOnDemand = false;
check($service->getBlurhash($asset) === null && $jobCount() === 0, 'Disabled on-demand setting queued work.');
$plugin->getSettings()->computeOnDemand = true;
check($service->getBlurhash($asset) === null && !$service->getHasTransparency($asset), 'Cold template lookup computed inline.');
check((new BlurhashService())->getBlurhash($asset) === null && $jobCount() === 1, 'Separate template lookups duplicated work.');
drain();
$row = BlurhashRecord::findOne(['assetId' => $asset->id]);
check($row !== null && $row->blurhash !== null && !$service->needsCompute($asset), 'First job did not persist a current hash.');
$original = $row->blurhash;
$asset->dateModified->setTimezone(new DateTimeZone('Europe/Zurich'));
check(!$service->needsCompute($asset), 'Timezone alone made a record stale.');
BlurhashRecord::updateAll(['sourceDateModified' => '2001-01-01 00:00:00'], ['id' => $row->id]);
check($service->needsCompute($asset), 'Older source time incorrectly skipped.');
(new m261119_000000_normalize_source_time())->safeUp();
check(!$service->needsCompute($asset), 'UTC migration did not fix a legacy row.');
$app->getAssets()->replaceAssetFile($asset, imageFile(0xffaa33), 'image.png');
check(BlurhashRecord::findOne($row->id)->blurhash === $original, 'Replacement discarded placeholder before worker.');
drain();
$replacement = BlurhashRecord::findOne($row->id);
check($replacement->blurhash !== $original && $replacement->sourceDateModified === Db::prepareDateForDb($asset->dateModified), 'Replacement did not refresh the existing row.');
BlurhashRecord::updateAll(['sourceDateModified' => '2001-01-01 00:00:00', 'blurhash' => $original], ['id' => $row->id]);
(new ComputeBlurhashJob(['assetId' => $asset->id]))->execute($queue);
check(BlurhashRecord::findOne($row->id)->blurhash === $replacement->blurhash, 'A normal stale job skipped an existing hash.');
check($service->getStats()['generated'] === 1, 'Processed image still reported missing.');
// The fixture doesn't run a console route for this stage, so flush the
// filesystem configuration as Craft would at the end of the request.
$app->getProjectConfig()->flush();
echo "Asset lifecycle, queue, migration and reporting checks passed.\n";
