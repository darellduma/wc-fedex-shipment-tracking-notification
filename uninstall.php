<?php

    if (!defined('WP_UNINSTALL_PLUGIN')) {
        die;
    }
    
    $option_names = [ 'fedex_settings_us', 'fedex_settings_ca' ];
    
    foreach( $option_names as $option_name ){
        delete_option($option_name);
    }
    
    flush_rewrite_rules();