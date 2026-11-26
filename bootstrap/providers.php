<?php

declare(strict_types=1);

/*
 * NOTE: if the starter kit's own `bootstrap/providers.php` listed providers
 * beyond AppServiceProvider, MERGE them into this list rather than letting
 * this file replace theirs.
 */

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,
];
