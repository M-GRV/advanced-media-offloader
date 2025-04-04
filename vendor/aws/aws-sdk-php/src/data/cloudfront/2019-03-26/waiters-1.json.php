<?php

namespace WPFitter;

// This file was auto-generated from sdk-root/src/data/cloudfront/2019-03-26/waiters-1.json
return ['waiters' => ['__default__' => ['success_type' => 'output', 'success_path' => 'Status'], 'StreamingDistributionDeployed' => ['operation' => 'GetStreamingDistribution', 'description' => 'Wait until a streaming distribution is deployed.', 'interval' => 60, 'max_attempts' => 25, 'success_value' => 'Deployed'], 'DistributionDeployed' => ['operation' => 'GetDistribution', 'description' => 'Wait until a distribution is deployed.', 'interval' => 60, 'max_attempts' => 25, 'success_value' => 'Deployed'], 'InvalidationCompleted' => ['operation' => 'GetInvalidation', 'description' => 'Wait until an invalidation has completed.', 'interval' => 20, 'max_attempts' => 30, 'success_value' => 'Completed']]];
