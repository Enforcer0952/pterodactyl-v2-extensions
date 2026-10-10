<?php

namespace McContent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plugin or mod this extension installed into a server, so the Installed list can
 * show its Modrinth name, version and whether an update is available.
 *
 * @property int $id
 * @property int $server_id
 * @property string $kind
 * @property string $filename
 * @property string $project_id
 * @property string $version_id
 * @property string $title
 * @property string $version_number
 * @property string|null $icon_url
 * @property string|null $sha1
 */
class Install extends Model
{
    protected $table = 'ext_mc_content_installs';

    protected $fillable = [
        'server_id', 'kind', 'filename', 'project_id', 'version_id',
        'title', 'version_number', 'icon_url', 'sha1',
    ];
}
