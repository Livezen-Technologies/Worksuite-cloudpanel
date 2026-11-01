<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** UAI: one assistant conversation. History is JSON (encrypted): plain content blocks, passed back unchanged. */
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
        $v = json_decode(Crypt::decryptString($this->history), true);
        if (! is_array($v)) {
            return [];
        }
        // JSON can't tell {} from []: an empty tool input must go back to the API as an object.
        foreach ($v as &$m) {
            foreach (is_array($m['content'] ?? null) ? $m['content'] : [] as $i => $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? []) === []) {
                    $m['content'][$i]['input'] = new \stdClass;
                }
            }
        }

        return $v;
    }

    public function setMessages(array $messages): void
    {
        $this->history = Crypt::encryptString(json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
