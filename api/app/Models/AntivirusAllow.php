<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** AV-7: a file hash an organisation marked "not a threat". */
class AntivirusAllow extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'antivirus_allowlist';

    protected $guarded = ['id'];
}
