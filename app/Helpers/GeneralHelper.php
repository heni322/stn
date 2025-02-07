<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;


if (!function_exists('handleImageUpload')) {
    function handleImageUpload($request, $fieldName, $path, $multiple = false)
    {
        $uploadedImages = [];

        if ($multiple) {
            if ($request->hasFile($fieldName)) {
                foreach ($request->file($fieldName) as $index => $imageFile) {
                    $uploadedImages[] = processImage($imageFile, $path, $index === 0);
                }
            }
            return $uploadedImages;
        } else {
            if ($request->hasFile($fieldName)) {
                return processImage($request->file($fieldName), $path);
            }
        }

        return $multiple ? [] : null;
    }
}

if (!function_exists('processImage')) {
    function processImage($imageFile, $path, $isPrimary = false)
    {
        $filename = Str::uuid() . '.' . $imageFile->getClientOriginalExtension();
        $imagePath = $imageFile->storeAs('public/' . $path, $filename);

        // Resize the image using Intervention Image
        Image::make(Storage::path($imagePath))
            ->resize(800, 600, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })
            ->save(Storage::path($imagePath));

        return [
            'path' => url('storage/' . $path . '/' . $filename),
            'is_primary' => $isPrimary,
        ];
    }
}

