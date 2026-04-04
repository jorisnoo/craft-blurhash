<?php

arch('source code does not contain dd or dump')
    ->expect('Noo\CraftBlurhash')
    ->not->toUse(['dd', 'dump']);
