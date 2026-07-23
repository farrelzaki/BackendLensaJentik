<?php
namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;

class CloudinaryService
{
    protected Cloudinary $cloudinary;

    public function __construct()
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                'api_key' => env('CLOUDINARY_API_KEY'),
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ],
        ]);
    }

    /**
     * Upload file dan return secure URL-nya.
     */
    public function upload(UploadedFile $file, string $folder = 'lensajentik'): string
    {
        $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder' => $folder,
        ]);

        return $result['secure_url'];
    }
}