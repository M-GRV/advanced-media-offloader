<?php

namespace Advanced_Media_Offloader;

use Advanced_Media_Offloader\Abstracts\S3_Provider;
use Advanced_Media_Offloader\Traits\OffloaderTrait;
use Advanced_Media_Offloader\Interfaces\ObserverInterface;
use Advanced_Media_Offloader\Observers\AttachmentUrlObserver;
use Advanced_Media_Offloader\Observers\AttachmentDeleteObserver;
use Advanced_Media_Offloader\Observers\OffloadStatusObserver;
use Advanced_Media_Offloader\Observers\ImageSrcsetObserver;
use Advanced_Media_Offloader\Observers\ImageSrcsetMetaObserver;
use Advanced_Media_Offloader\Observers\AttachmentUploadObserver;
use Advanced_Media_Offloader\Observers\PostContentImageTagObserver;
use Advanced_Media_Offloader\Observers\AttachmentUpdateObserver;
use Advanced_Media_Offloader\Integrations\WatermarkIntegration;

class Offloader
{
    use OffloaderTrait;

    private static $instance = null;
    public $cloudProvider;
    private array $observers = [];
    private function __construct(S3_Provider $cloudProvider)
    {
        $this->cloudProvider = $cloudProvider;
    }

    public static function get_instance(S3_Provider $cloudProvider)
    {
        if (self::$instance === null) {
            self::$instance = new self($cloudProvider);
        }
        return self::$instance;
    }

    public function initializeHooks()
    {
        // First, add the watermark integration if the plugin is active
        if ($this->isWatermarkPluginActive()) {
            $this->attach(new WatermarkIntegration($this->cloudProvider));
        }
        
        // Then add all other observers
        $this->attach(new AttachmentUploadObserver($this->cloudProvider));
        $this->attach(new ImageSrcsetObserver($this->cloudProvider));
        $this->attach(new ImageSrcsetMetaObserver($this->cloudProvider));
        $this->attach(new AttachmentUrlObserver($this->cloudProvider));
        $this->attach(new OffloadStatusObserver($this->cloudProvider));
        $this->attach(new AttachmentDeleteObserver($this->cloudProvider));
        $this->attach(new PostContentImageTagObserver($this->cloudProvider));
        $this->attach(new AttachmentUpdateObserver($this->cloudProvider));

        // Register all observers
        foreach ($this->observers as $observer) {
            $observer->register();
        }
        
        // Add filter to allow integration classes to control offloading process
        add_filter('advmo_should_offload_attachment', function($should_offload, $attachment_id) {
            return $should_offload;
        }, 5, 2);
    }

    public function attach(ObserverInterface $observer)
    {
        $this->observers[] = $observer;
    }

    public function detach(ObserverInterface $observer)
    {
        foreach ($this->observers as $key => $obs) {
            if ($obs === $observer) {
                unset($this->observers[$key]);
            }
        }
    }
    
    /**
     * Check if the Image Watermark plugin from dFactory is active
     *
     * @return bool
     */
    private function isWatermarkPluginActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        return is_plugin_active('image-watermark/image-watermark.php');
    }
}