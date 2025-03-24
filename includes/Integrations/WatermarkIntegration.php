<?php

namespace Advanced_Media_Offloader\Integrations;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;

/**
 * Integration with dFactory Image Watermark plugin
 * 
 * This class handles the integration between Advanced Media Offloader
 * and the Image Watermark plugin from dFactory.
 * 
 * It ensures media files are only offloaded after watermarks have been applied.
 */
class WatermarkIntegration implements ObserverInterface
{
    /**
     * @var S3_Provider
     */
    private S3_Provider $cloudProvider;

    /**
     * @var CloudAttachmentUploader
     */
    private CloudAttachmentUploader $cloudAttachmentUploader;

    /**
     * @var array
     */
    private array $pendingAttachments = [];

    /**
     * Constructor.
     *
     * @param S3_Provider $cloudProvider
     */
    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    /**
     * Register the observer with WordPress hooks.
     *
     * @return void
     */
    public function register(): void
    {
        // Prevent the default upload observer from processing images
        add_filter('advmo_should_offload_attachment', [$this, 'shouldOffloadAttachment'], 10, 2);
        
        // Hook into the Image Watermark plugin process
        add_action('iw_before_apply_watermark', [$this, 'beforeWatermark'], 10, 1);
        add_action('iw_after_apply_watermark', [$this, 'afterWatermark'], 10, 1);
        
        // Add a fallback for non-watermarked images and other media types
        add_action('wp_generate_attachment_metadata', [$this, 'maybeProcessNonWatermarkedAttachment'], 999, 2);
    }

    /**
     * Determine if an attachment should be offloaded by the default process.
     * 
     * This prevents the default observer from offloading image files
     * that need to be watermarked first.
     *
     * @param bool $should_offload Whether the attachment should be offloaded
     * @param int $attachment_id The attachment ID
     * @return bool
     */
    public function shouldOffloadAttachment(bool $should_offload, int $attachment_id): bool
    {
        // Skip images if Image Watermark plugin is active and configured
        if (wp_attachment_is_image($attachment_id) && $this->isWatermarkingActive()) {
            // Add to pending list to track if watermarking is skipped
            $this->pendingAttachments[$attachment_id] = time();
            return false;
        }
        
        return $should_offload;
    }

    /**
     * Record when a watermark process starts.
     *
     * @param array $image The image data
     * @return void
     */
    public function beforeWatermark($image): void
    {
        if (!isset($image['attachment_id'])) {
            return;
        }
        
        $attachment_id = $image['attachment_id'];
        
        // Store the attachment ID to track that watermarking is happening
        $this->pendingAttachments[$attachment_id] = time();
        
        // Log for debugging
        error_log("Advanced Media Offloader: Watermarking starting for attachment {$attachment_id}");
    }

    /**
     * Process the attachment after watermarking is complete.
     *
     * @param array $image The image data
     * @return void
     */
    public function afterWatermark($image): void
    {
        if (!isset($image['attachment_id'])) {
            return;
        }
        
        $attachment_id = $image['attachment_id'];
        
        // Log for debugging
        error_log("Advanced Media Offloader: Watermarking complete for attachment {$attachment_id}, now offloading");
        
        // Remove from pending list
        if (isset($this->pendingAttachments[$attachment_id])) {
            unset($this->pendingAttachments[$attachment_id]);
        }
        
        // Now we can safely offload the watermarked image
        $this->cloudAttachmentUploader->uploadAttachment($attachment_id);
    }
    
    /**
     * Process attachments that may have been skipped by watermarking.
     * 
     * This is a fallback to ensure all attachments are eventually processed,
     * even if the watermarking process doesn't apply to them or is skipped.
     *
     * @param array $metadata Attachment metadata
     * @param int $attachment_id Attachment ID
     * @return array Unmodified metadata
     */
    public function maybeProcessNonWatermarkedAttachment($metadata, $attachment_id): array
    {
        // Wait a short time to ensure watermarking has had a chance to start
        if (wp_attachment_is_image($attachment_id) && $this->isWatermarkingActive()) {
            // If this is still in our pending list after 5 seconds, it might have been skipped by watermarking
            if (isset($this->pendingAttachments[$attachment_id])) {
                $pending_time = $this->pendingAttachments[$attachment_id];
                
                // Only process if it's been waiting for at least 5 seconds
                if ((time() - $pending_time) > 5) {
                    error_log("Advanced Media Offloader: No watermarking detected for attachment {$attachment_id}, processing anyway");
                    $this->cloudAttachmentUploader->uploadAttachment($attachment_id);
                    unset($this->pendingAttachments[$attachment_id]);
                }
            }
        } else {
            // For non-image files or when watermarking is disabled, process immediately
            if (!isset($this->pendingAttachments[$attachment_id])) {
                $this->cloudAttachmentUploader->uploadAttachment($attachment_id);
            }
        }
        
        return $metadata;
    }
    
    /**
     * Check if Image Watermark plugin is active and configured.
     *
     * @return bool
     */
    private function isWatermarkingActive(): bool
    {
        // Check if the Image Watermark plugin is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        if (!is_plugin_active('image-watermark/image-watermark.php')) {
            return false;
        }
        
        // Check if watermarking is enabled in the plugin settings
        $options = get_option('image_watermark_options');
        
        if (!$options) {
            return false;
        }
        
        // Check if automatic watermarking is enabled
        return isset($options['watermark_on']) && $options['watermark_on'] === true;
    }
}