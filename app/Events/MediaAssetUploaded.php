<?php

namespace App\Events;

use App\Models\MediaAsset;
use Illuminate\Foundation\Events\Dispatchable;

class MediaAssetUploaded
{
    use Dispatchable;

    public function __construct(public readonly MediaAsset $asset) {}
}
