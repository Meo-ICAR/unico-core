<?php

namespace Unico\Core\Models;

use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Unico\Core\Concerns\UsesCoreConnection;

/**
 * Allegati di Spatie Media Library sul database core.
 * Va indicato in config/media-library.php come `media_model`.
 */
class Media extends BaseMedia
{
    use UsesCoreConnection;

    protected $table = 'media';
}
