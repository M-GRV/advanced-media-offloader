<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Services\CloudAttachmentUploader;

class AttachmentUploadObserver implements ObserverInterface
{
    private CloudAttachmentUploader $cloudAttachmentUploader;

    public function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudAttachmentUploader = new CloudAttachmentUploader($cloudProvider);
    }

    public function register(): void
    {
        add_filter('wp_generate_attachment_metadata', [$this, 'run'], 99, 2);
    }

    public function run($metadata, $attachment_id)
    {
        // Allow other integrations to control whether this attachment should be offloaded now
        $should_offload = apply_filters('advmo_should_offload_attachment', true, $attachment_id);
        
        if ($should_offload) {
            if (!$this->cloudAttachmentUploader->uploadAttachment($attachment_id)) {
                wp_delete_attachment($attachment_id, true);
            }
        } else {
            // Log for debugging that offloading is being delayed
            error_log("Advanced Media Offloader: Offloading delayed for attachment {$attachment_id} - waiting for another process");
        }

        return $metadata;
    }
}