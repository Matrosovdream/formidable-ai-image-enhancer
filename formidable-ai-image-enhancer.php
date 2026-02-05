<?php
/*
Plugin Name: Formidable AI Image Enhancer
Description: 
Version: 1.0
Plugin URI: 
Author URI: 
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}



// Variables
define('FRM_AI_BASE_URL', __DIR__);
define('FRM_AI_BASE_PATH', plugin_dir_url(__FILE__));

// References
require_once 'references.php';

// Initialize core
require_once 'classes/FrmImageEnhancerInit.php';

add_action('init', function() {
    
    if( isset( $_GET['gemini'] ) ) {

        geminiTest();
        exit();

    }

});


function geminiTest() {

    require_once FRM_AI_BASE_URL . '/gemini-test.php';

    $apiKey = 'AIzaSyAyG1o9W0EAQ8Yim0tWHJsqwSFdfoEWTxw';
    $inputFilePath = __DIR__ . '/image-2.jpg';

    $client = new GeminiNanoBananaClient($apiKey);

    $result = $client->processImage(
        $inputFilePath,
        'Turn this into cats world.'
    );

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);


}