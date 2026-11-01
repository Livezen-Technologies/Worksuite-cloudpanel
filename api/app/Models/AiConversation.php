<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** UAI: one assistant conversation. History holds SDK blocks, so it is serialised (and encrypted). */
class AiConversation extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['history'];

    protected function casts(): array
    {
        return ['transcript' => 'encrypted:array'];
    }

    public function messages(): array
    {
        if (! $this->history) {
            return [];
        }
        $v = unserialize(Crypt::decryptString($this->history)); // our own encrypted data

        return is_array($v) ? $v : [];
    }

    public function setMessages(array $messages): void
    {
        $this->history = Crypt::encryptString(serialize($messages));
    }
}
