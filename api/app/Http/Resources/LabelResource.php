<?php

namespace App\Http\Resources;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Label */
class LabelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_label_id' => $this->provider_label_id,
            'name' => $this->name,
            'type' => $this->type,
            'color_bg' => $this->color_bg,
            'color_fg' => $this->color_fg,
            'visible' => $this->visible,
        ];
    }
}
