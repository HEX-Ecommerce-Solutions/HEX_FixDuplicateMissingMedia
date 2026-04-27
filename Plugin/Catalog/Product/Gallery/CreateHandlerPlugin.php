<?php
declare(strict_types=1);

namespace HEX\FixDuplicateMissingMedia\Plugin\Catalog\Product\Gallery;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\MediaStorage\Helper\File\Storage\Database as FileStorageDb;

final class CreateHandlerPlugin
{
    private WriteInterface $mediaDirectory;

    public function __construct(
        private readonly JsonHelper $jsonHelper,
        private readonly MediaConfig $mediaConfig,
        private readonly FileStorageDb $fileStorageDb,
        Filesystem $filesystem
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * During product duplication (adminhtml), Magento copies existing media-gallery files.
     * If the DB references a file that no longer exists, core throws:
     * "We couldn't copy file %1. Please delete media with non-existing images and try again."
     *
     * This plugin filters out missing files before the core handler runs, allowing duplication to proceed.
     */
    public function aroundExecute(
        \Magento\Catalog\Model\Product\Gallery\CreateHandler $subject,
        callable $proceed,
        $product,
        $arguments = []
    ) {
        try {
            if (!$product || $product->getIsDuplicate() != true) {
                return $proceed($product, $arguments);
            }

            $attrCode = 'media_gallery';
            $value = $product->getData($attrCode);
            if (!is_array($value) || !isset($value['images'])) {
                return $proceed($product, $arguments);
            }

            if (!is_array($value['images']) && strlen((string) $value['images']) > 0) {
                $value['images'] = $this->jsonHelper->jsonDecode($value['images']);
            }

            if (!is_array($value['images']) || !$value['images']) {
                return $proceed($product, $arguments);
            }

            $missingFiles = [];
            $filteredImages = [];

            foreach ($value['images'] as $image) {
                $file = (string) ($image['file'] ?? '');
                $valueId = $image['value_id'] ?? null;
                $removed = (string) ($image['removed'] ?? '');

                // Only matters for duplication branch; core skips items without value_id or removed.
                if (empty($valueId) || $removed !== '' || $file === '') {
                    $filteredImages[] = $image;
                    continue;
                }

                if ($this->fileExists($file)) {
                    $filteredImages[] = $image;
                    continue;
                }

                $missingFiles[] = $file;
                // Drop the image from duplication request payload.
            }

            if ($missingFiles) {
                $value['images'] = $filteredImages;
                $product->setData($attrCode, $value);

                // Also clear base image attributes if they point to a missing file; otherwise the duplicate
                // might still reference a non-existent image.
                foreach ($this->mediaConfig->getMediaAttributeCodes() as $code) {
                    $current = (string) $product->getData($code);
                    if ($current !== '' && in_array($current, $missingFiles, true)) {
                        $product->setData($code, 'no_selection');
                    }
                }
            }

            return $proceed($product, $arguments);
        } catch (LocalizedException $e) {
            // Keep core behavior for other failures.
            throw $e;
        }
    }

    private function fileExists(string $file): bool
    {
        $file = ltrim($file, '/');

        $relativeMediaPath = $this->mediaConfig->getMediaPath($file);
        if ($this->mediaDirectory->isFile($relativeMediaPath)) {
            return true;
        }

        if ($this->fileStorageDb->checkDbUsage()) {
            $shortUrl = $this->mediaConfig->getMediaShortUrl($file);
            return (bool) $this->fileStorageDb->fileExists($shortUrl);
        }

        return false;
    }
}
