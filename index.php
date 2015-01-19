<?php

require 'vendor/autoload.php';
require 'code/getLoginEmail.php';
require 'code/helper.php';
use Aws\Common\Aws;
use Aws\Ses\SesClient;
use Aws\DynamoDb\DynamoDbClient;

// Prepare Slim PHP app
$app = new \Slim\Slim(array(
    'templates.path' => 'templates'
));

// Set timezone
date_default_timezone_set("UTC"); 

$app->get('/', function () use ($app) {

    // Render index view
    $app->render('index.html');
});

$app->get('/api/token', function () use ($app) {

	// The old token should be sent in request header
	$token = $app->request->headers->get('X-Authorization');

	$app->response->headers->set('Content-Type', 'application/json');

	// If the user had a token in their local storage, refresh it and send the new one back.
	if (isset($token)) {

		// Get email, expiration timestamp, and signature from old token
		$oldToken = explode(':', $token);
		$email = $oldToken[0];
		$expirationTimestamp = $oldToken[1];
		$givenSignature = $oldToken[2];

		// Setup dates to check if token is expired
		$currentDate = new DateTime();
        $expirationDate = new DateTime();
		$expirationDate->setTimestamp(intval($expirationTimestamp));

		// Setup expected signature for purposes of comparison.
		$rawToken = $email . ':' . $expirationTimestamp;
		$expectedSignature = hash_hmac('ripemd160', $rawToken, 'secret');

		if ($currentDate >= $expirationDate) {

			// The token is expired
            $app->response->setBody(json_encode(array('token' => 'InvalidToken')));

		} else if (md5($givenSignature) === md5($expectedSignature)) {

			// All is well and we can finally refresh the auth token
			$newRawToken = $email . ':' . strtotime('+7 days');
			$newSignature = hash_hmac('ripemd160', $newRawToken, 'secret');
			$newAuthToken = $newRawToken . ':' . $newSignature;
                        
            $app->response->setBody(json_encode(array('token' => $newAuthToken)));
		
		} else {

			// The token is invalid and has probably been tampered with
            $app->response->setBody(json_encode(array('token' => 'InvalidToken')));

		}
		
	} else {

		// User didn't supply a token to be refreshed so this is either an invalid request or
		// they just opened the appication.
        $app->response->setBody(json_encode(array('token' => 'InvalidToken')));
    }

});

$app->post('/api/login', function () use ($app) {

    $app->response->headers->set('Content-Type', 'application/json');
	$email = $app->request->post('email');

	// Establish AWS Clients
	$sesClient = SesClient::factory(array(
        'region'  => 'us-west-2'
	));

	$tokenId = Helper::GUID();

	$msg = array();
	$msg['Source'] = "ny2244111@hotmail.com";
	//ToAddresses must be an array
	$msg['Destination']['ToAddresses'][] = $email;

	$msg['Message']['Subject']['Data'] = "Notello login email";
	$msg['Message']['Subject']['Charset'] = "UTF-8";

	$msg['Message']['Body']['Text']['Data'] = getLoginTextEmail($email, $tokenId);
	$msg['Message']['Body']['Text']['Charset'] = "UTF-8";
	$msg['Message']['Body']['Html']['Data'] = getLoginHTMLEmail($email, $tokenId);
	$msg['Message']['Body']['Html']['Charset'] = "UTF-8";

	try {

	    $result = $sesClient->sendEmail($msg);

	    //save the MessageId which can be used to track the request
	    $msg_id = $result->get('MessageId');

	    //view sample output
            echo json_encode(true);

	} catch (Exception $e) {
	    //An error happened and the email did not get sent
	    echo($e->getMessage());
	}
});

$app->get('/authenticate', function () use ($app) {

    // Get tokenId from query string which is most likely given from login email
	$tokenId = $app->request->get('token');

	// Get rid of any left over tempAuthTokens.
	$app->deleteCookie('tempAuthToken');

	if (isset($tokenId)) {

		// Get AWS DynamoDB Client
		$dbClient = DynamoDBClient::factory(array(
        	'region'  => 'us-west-2'
		));

		// Query token in Database
		$result = $dbClient->getItem(array(
		    'ConsistentRead' => true,
		    'TableName' => 'tokens',
		    'Key'       => array(
		        'tokenId'   => array('S' => $tokenId)
		    )
		));

		// Get email from query result
		$email = $result['Item']['email']['S'];

		// If the email is not there, the token has been deleted or is just invalid
		if (isset($email)) {

			// Get inserted date from query result for comparison purposes
			$insertedDateTimeStamp = $result['Item']['insertedDate']['N'];
			$insertedDate = DateTime::createFromFormat( 'U', $insertedDateTimeStamp);
			$currentTime = new DateTime();

			// Delete token from database regardless of whether it's expired or not.
			// Query each token for the given email
			$scan = $dbClient->getIterator('Query', array(
					'TableName' => 'tokens',
			    	'IndexName' => 'email-index',
			    	'KeyConditions' => array(
				        'email' => array(
				            'AttributeValueList' => array(
				                array('S' => $email)
				            ),
				            'ComparisonOperator' => 'EQ'
				        )
			    	)
				)
			);

			// Delete each item for the given email
			foreach ($scan as $item) {

				$dbClient->deleteItem(array(
				        'TableName' => 'tokens',
				        'Key' => array(
				            'tokenId' => array('S' => $item['tokenId']['S'])
				     	)
					)
				);
			}

			// If the token is over 1 hour old then it is considered invalid and we don't authenticate the user
			if (date_diff($insertedDate, $currentTime)->h > 1) {

				$app->setCookie('tempAuthToken', 'expired', '5 minutes', '/', null, true);

			} else {

				$rawToken = $email . ':' . strtotime('+7 days');
				$signature = hash_hmac('ripemd160', $rawToken, 'secret');
				$authToken = $rawToken . ':' . $signature;

				$app->setCookie('tempAuthToken', $authToken, '5 minutes', '/', null, true);

			}

		} else {

			// Invalid or deleted token
			$app->setCookie('tempAuthToken', 'invalid', '5 minutes', '/', null, true);
		}

	}

	$app->response->redirect('/', 303);

});

// Run app
$app->run();