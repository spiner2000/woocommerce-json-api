<?php
/**
 * Core JSON API
*/
// Error Codes are negative, Warning codes are positive
define('WCAPI_EXPECTED_ARGUMENT',             -1);
define('WCAPI_NOT_IMPLEMENTED',               -2);
define('WCAPI_UNEXPECTED_ERROR',              -3);
define('WCAPI_INVALID_CREDENTIALS',           -4);
define('WCAPI_BAD_ARGUMENT',                  -5);
define('WCAPI_CANNOT_INSERT_RECORD',          -6);
define('WCAPI_PERMSNOTSET',                   -7);
define('WCAPI_PERMSINSUFF',                   -8);
define('WCAPI_INTERNAL_ERROR',                -9);

define('WCAPI_PRODUCT_NOT_EXISTS', 1);
define('WCAPI_ORDER_NOT_EXISTS', 2);

require_once( plugin_dir_path(__FILE__) . '/class-rede-helpers.php' );
require_once( plugin_dir_path(__FILE__) . '/class-wc-json-api-result.php' );
require_once( dirname(__FILE__) . '/WCAPI/includes.php' );

use WCAPI as API;

if ( !defined('PHP_VERSION_ID')) {
  $version = explode('.',PHP_VERSION);
  if ( PHP_VERSION_ID < 50207 ) {
    define('PHP_MAJOR_VERSION',$version[0]);
    define('PHP_MINOR_VERSION',$version[1]);
    define('PHP_RELEASE_VERSION',$version[2]);
  }
}
class WooCommerce_JSON_API extends JSONAPIHelpers {
    // Call this function to setup a new response
  public $helpers;
  public $result;
  public $return_type;
  public $the_user;
  public $provider;
  public static $implemented_methods;

  public function setOut($t) {
    $this->return_type = $t;
  }
  public function setUser($user) {
    $this->the_user = $user;
  }
  public function getUser() {
    return $this->the_user;
  }
 
  public function __construct() {
    //$this = new JSONAPIHelpers();
    $this->result = null;
    $this->provider = null;
  }
  /**
  *  This function is the single entry point into the API.
  *  
  *  The order of operations goes like this:
  *  
  *  1) A new result object is created.
  *  2) Check to see if it's a valid API User, if not, do stuff and quit
  *  3) Check to see if the method requested has been implemented
  *  4) If it's implemented, call and turn over control to the method
  *  
  *  This function takes a single hash,  usually $_REQUEST
  *  
  *  WHY? 
  *  
  *  Well, as you will notice with WooCommerce, there is an irritatingly large
  *  dependence on _defined_ and $_GET/$_POST variables, throughout their plugin,
  *  each function "depends" on request state, which is fine, except this
  *  violates 'dependency injection'. We don't know where data might come from
  *  in the future, what if another plugin wants to call this one inside of PHP
  *  within a request, multiple times? 
  *  
  *  No module should ever 'depend' on objects outside of itself, they should be
  *  provided with operating data, or 'injected' with it.
  *  
  *  There is nothing 'wrong' with the way WooCommerce does things, only it leads
  *  to a certain inflexibility in what you can do with it.
  */
  public function route( $params ) {
    
    /*
     * The idea behind the provider is that there will be
     * several versions of the API in the future, and the
     * user can choose which one they are writing against.
     * This simplifies the provider files a bit and makes
     * the code more modular.
     */
    $version = intval($this->orEq($params, 'version', 1));
    if ( ! is_numeric($version) ) {
      $version = 1;
    }
    if ( file_exists( dirname(__FILE__ ) .'/API_VERSIONS/version'.$version.'.php' ) ) {
      require_once( dirname(__FILE__ ) .'/API_VERSIONS/version'.$version.'.php' );
      $this->provider = new WC_JSON_API_Provider( $this );
    }
    $this->createNewResult( $params );

    JSONAPIHelpers::debug( "Beggining request" );
    JSONAPIHelpers::debug( var_export($params,true));

    if ( ! $this->isValidAPIUser( $params ) ) {

      $this->result->addError( 
        __('Not a valid API User', 'woocommerce_json_api' ), 
        WCAPI_INVALID_CREDENTIALS 
      );
      return $this->done();

    }
    if ( $this->provider->isImplemented( $params ) ) {

      try {

        // The arguments are passed by reference here
        $this->validateParameters( $params['arguments'], $this->result);
        if ( $this->result->status() == false ) {
          JSONAPIHelpers::warn("Arguments did not pass validation");
          return $this->done();
        }
        return $this->provider->{ $params['proc'] }($params);

      } catch ( Exception $e ) {
        JSONAPIHelpers::error($e->getMessage());
        $this->unexpectedError( $params, $e);
      }
    } else {
      JSONAPIHelpers::warn("{$params['proc']} is not implemented...");
      $this->notImplemented( $params );
    }
  }
  public function isValidAPIUser( $params ) {
    if ( $this->the_user ) {
      return true;
    }
    if ( ! isset($params['arguments']) ) {
      $this->result->addError( __( 'Missing `arguments` key','woocommerce_json_api' ),WCAPI_EXPECTED_ARGUMENT );
      return false;
    }
    $by_token = true;
    if ( ! isset( $params['arguments']['token'] ) ) {
      
      if ( 
        isset( $params['arguments']['username'] ) && 
        isset( $params['arguments']['password']) 
      ) {

        $by_token = false;

      } else {
        $this->result->addError( __( 'Missing `token` in `arguments`','woocommerce_json_api' ),WCAPI_EXPECTED_ARGUMENT );
        return false;
      }
      
    }
    $key = $this->getPluginPrefix() . '_settings';
    
    $args = array(
      'blog_id' => $GLOBALS['blog_id'],
      'meta_key' => $key
    );

    API\Base::setBlogId($GLOBALS['blog_id']);

    $users = get_users( $args );

    if (! $by_token ) {

        $user = wp_authenticate_username_password( null, $params['arguments']['username'],$params['arguments']['password']);
        
        if ( is_a($user,'WP_Error') ) {
          foreach( $user->get_error_messages() as $msg) {
            $this->result->addError( $msg ,WCAPI_INTERNAL_ERROR );
          }
          return false;
        }
        $this->logUserIn($user);
        return true;

    }
    foreach ($users as $user) {
      
      $meta = maybe_unserialize( get_user_meta( $user->ID, $key, true ) );

      if (isset( $meta['token']) &&  $params['arguments']['token'] == $meta['token']) {
        if (
          !isset($meta[ 'can_' . $params['proc'] ]) || 
          !isset($meta[ 'can_access_the_api' ])
        ) {

          $this->result->addError( __( 'Permissions for this user have not been set','woocommerce_json_api' ),WCAPI_PERMSNOTSET );
          return false;

        }
        if ( $meta[ 'can_access_the_api' ] == 'no' ) {

          $this->result->addError( __( 'You have been banned.','woocommerce_json_api' ), WCAPI_PERMSINSUFF );
          
          return false;
        }
        if ( $meta[ 'can_' . $params['proc'] ] == 'no' ) {

          $this->result->addError( __( 'You do not have sufficient permissions.','woocommerce_json_api' ), WCAPI_PERMSINSUFF );
          
          return false;

        }
        
        $this->logUserIn($user);
        return true;

      }

    }

    return false;
  }
  public function logUserIn( $user ) {

    wp_set_current_user($user->ID);
    wp_set_auth_cookie( $user->ID, false, is_ssl() );
    $this->setUser($user);

  }
  public function unexpectedError( $params, $error ) {
    $this->createNewResult( $params );

    $this->result->addError( 
      __('An unexpected error has occured', 'woocommerce_json_api' ) . $error->getMessage(), 
      WCAPI_UNEXPECTED_ERROR 
    );

    return $this->done();
  }
  public function createNewResult($params) {
    if ( ! $this->result ) {

      $this->result = new WooCommerce_JSON_API_Result();
      $this->result->setParams( $params );

    }
  }
  public function done() {
    wp_logout();
    if ( $this->return_type == 'HTTP') {
      header("Content-type: application/json");
      echo( $this->result->asJSON() );
      die;
    } else if ( $this->return_type == "ARRAY") {

      return $this->result->getParams();

    } else if ( $this->return_type == "JSON") {

      return $this->result->asJSON();

    } else if ( $this->return_type == "OBJECT") {

      return $this->result;

    } 
  }
  public function notImplemented( $params ) {
    $this->createNewResult( $params );

    if ( !isset($params['proc']) ) {

      $this->result->addError( 
          __('Expected argument was not present', 'woocommerce_json_api') . ' `proc`',
           WCAPI_EXPECTED_ARGUMENT 
      );
    }

    $this->result->addError( 
      __('That API method has not been implemented', 'woocommerce_json_api' ), 
      WCAPI_NOT_IMPLEMENTED 
    );

    return $this->done();
  }
}
