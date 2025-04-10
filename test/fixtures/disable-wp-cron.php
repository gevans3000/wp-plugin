<?php
/**
 * Script to safely disable WordPress default cron
 * This should be run once to modify wp-config.php
 */

// Path to wp-config.php (adjust if needed)
$config_file = dirname(__FILE__) . '/../../../wp-config.php';

if (!file_exists($config_file)) {
    die("Cannot find wp-config.php\n");
}

// Read the config file
$config_content = file_get_contents($config_file);

// Check if DISABLE_WP_CRON is already defined
if (strpos($config_content, 'DISABLE_WP_CRON') === false) {
    // Find the line where WordPress settings start
    $insert_position = strpos($config_content, "/* That's all, stop editing!");
    
    if ($insert_position === false) {
        // If we can't find the standard comment, insert at the end
        $insert_position = strpos($config_content, '<?php') + 5;
    }
    
    // Prepare the new constant definition
    $new_constant = "\n\n/* Disable default WordPress cron */\ndefine('DISABLE_WP_CRON', true);\n";
    
    // Insert the new constant
    $new_content = substr_replace($config_content, $new_constant, $insert_position, 0);
    
    // Create a backup
    copy($config_file, $config_file . '.backup');
    
    // Write the modified content
    if (file_put_contents($config_file, $new_content)) {
        echo "Successfully disabled WordPress default cron\n";
    } else {
        echo "Failed to modify wp-config.php\n";
    }
} else {
    echo "DISABLE_WP_CRON is already defined in wp-config.php\n";
}
