<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'site_id' => new SiteResource(Site::find($this->site_id)),
            'category_id' => new CategoryResource(Category::find($this->category_id)),
            'images' => ProductImageResource::collection($this->images),
            'variants' => ProductVariantResource::collection($this->variants),
            'created_at' => $this->created_at->toDateTimeString(),
            'updated_at' => $this->updated_at->toDateTimeString(),
        ];
    }
}
