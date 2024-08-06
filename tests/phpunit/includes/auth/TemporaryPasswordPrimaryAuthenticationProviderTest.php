<?php

namespace MediaWiki\Tests\Auth;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Auth\PrimaryAuthenticationProvider;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\Auth\TemporaryPasswordPrimaryAuthenticationProvider;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\MultiConfig;
use MediaWiki\MainConfigNames;
use MediaWiki\Password\PasswordFactory;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Unit\Auth\AuthenticationProviderTestTrait;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\User\User;
use MediaWiki\User\UserNameUtils;
use MediaWikiIntegrationTestCase;
use StatusValue;
use Wikimedia\ScopedCallback;
use Wikimedia\TestingAccessWrapper;

/**
 * TODO clean up and reduce duplication
 *
 * @group AuthManager
 * @group Database
 * @covers \MediaWiki\Auth\TemporaryPasswordPrimaryAuthenticationProvider
 */
class TemporaryPasswordPrimaryAuthenticationProviderTest extends MediaWikiIntegrationTestCase {
	use AuthenticationProviderTestTrait;
	use DummyServicesTrait;

	private $manager = null;
	private $config = null;
	private $validity = null;

	/**
	 * Get an instance of the provider
	 *
	 * $provider->checkPasswordValidity is mocked to return $this->validity,
	 * because we don't need to test that here.
	 *
	 * @param array $params
	 * @return TemporaryPasswordPrimaryAuthenticationProvider
	 */
	protected function getProvider( $params = [] ) {
		$mwServices = $this->getServiceContainer();
		if ( !$this->config ) {
			$this->config = new HashConfig( [
				MainConfigNames::EnableEmail
			] );
		}
		$config = new MultiConfig( [
			$this->config,
			$mwServices->getMainConfig()
		] );
		$hookContainer = $this->createHookContainer();

		if ( !$this->manager ) {
			$userNameUtils = $this->createNoOpMock( UserNameUtils::class );

			$this->manager = new AuthManager(
				new FauxRequest(),
				$config,
				$this->getDummyObjectFactory(),
				$hookContainer,
				$mwServices->getReadOnlyMode(),
				$userNameUtils,
				$mwServices->getBlockManager(),
				$mwServices->getWatchlistManager(),
				$mwServices->getDBLoadBalancer(),
				$mwServices->getContentLanguage(),
				$mwServices->getLanguageConverterFactory(),
				$mwServices->getBotPasswordStore(),
				$mwServices->getUserFactory(),
				$mwServices->getUserIdentityLookup(),
				$mwServices->getUserOptionsManager()
			);
		}
		$this->validity = Status::newGood();

		$mockedMethods[] = 'checkPasswordValidity';
		$provider = $this->getMockBuilder( TemporaryPasswordPrimaryAuthenticationProvider::class )
			->onlyMethods( $mockedMethods )
			->setConstructorArgs( [
				$mwServices->getConnectionProvider(),
				$mwServices->getUserOptionsLookup(),
				$params
			] )
			->getMock();
		$provider->method( 'checkPasswordValidity' )
			->willReturnCallback( function () {
				return $this->validity;
			} );
		$this->initProvider(
			$provider, $config, null, $this->manager, null, $this->getServiceContainer()->getUserNameUtils()
		);

		return $provider;
	}

	protected function hookMailer( $func = null ) {
		$hookContainer = $this->getServiceContainer()->getHookContainer();

		$this->clearHook( 'AlternateUserMailer' );

		if ( $func ) {
			$reset = $hookContainer->scopedRegister( 'AlternateUserMailer', $func );
		} else {
			$reset = $hookContainer->scopedRegister( 'AlternateUserMailer', function () {
				$this->fail( 'AlternateUserMailer hook called unexpectedly' );
				return false;
			} );
		}
		return $reset;
	}

	public function testBasics() {
		$provider = $this->getProvider();

		$this->assertSame(
			PrimaryAuthenticationProvider::TYPE_CREATE,
			$provider->accountCreationType()
		);

		$existingUserName = $this->getTestUser()->getUserIdentity()->getName();
		$this->assertTrue( $provider->testUserExists( $existingUserName ) );
		$this->assertTrue( $provider->testUserExists( lcfirst( $existingUserName ) ) );
		$this->assertFalse( $provider->testUserExists( 'DoesNotExist' ) );
		$this->assertFalse( $provider->testUserExists( '<invalid>' ) );

		$req = new PasswordAuthenticationRequest;
		$req->action = AuthManager::ACTION_CHANGE;
		$req->username = '<invalid>';
		$provider->providerChangeAuthenticationData( $req );
	}

	public function testConfig() {
		$config = new HashConfig( [
			MainConfigNames::EnableEmail => false,
			MainConfigNames::NewPasswordExpiry => 100,
			MainConfigNames::PasswordReminderResendTime => 101,
			MainConfigNames::AllowRequiringEmailForResets => false,
		] );

		$provider = new TemporaryPasswordPrimaryAuthenticationProvider(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getUserOptionsLookup()
		);
		$providerPriv = TestingAccessWrapper::newFromObject( $provider );
		$this->initProvider( $provider, $config );
		$this->assertSame( false, $providerPriv->emailEnabled );
		$this->assertSame( 100, $providerPriv->newPasswordExpiry );
		$this->assertSame( 101, $providerPriv->passwordReminderResendTime );

		$provider = new TemporaryPasswordPrimaryAuthenticationProvider(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->getServiceContainer()->getUserOptionsLookup(),
			[
				'emailEnabled' => true,
				'newPasswordExpiry' => 42,
				'passwordReminderResendTime' => 43,
				'allowRequiringEmailForResets' => true,
			]
		);
		$providerPriv = TestingAccessWrapper::newFromObject( $provider );
		$this->initProvider( $provider, $config );
		$this->assertSame( true, $providerPriv->emailEnabled );
		$this->assertSame( 42, $providerPriv->newPasswordExpiry );
		$this->assertSame( 43, $providerPriv->passwordReminderResendTime );
		$this->assertSame( true, $providerPriv->allowRequiringEmail );
	}

	public function testTestUserCanAuthenticate() {
		$user = self::getMutableTestUser()->getUser();

		$dbw = $this->getDb();
		// A is unsalted MD5 (thus fast) ... we don't care about security here, this is test only
		$passwordFactory = new PasswordFactory( $this->getConfVar( MainConfigNames::PasswordConfig ), 'A' );

		$pwhash = $passwordFactory->newFromPlaintext( 'password' )->toString();

		$provider = $this->getProvider();
		$providerPriv = TestingAccessWrapper::newFromObject( $provider );

		$this->assertFalse( $provider->testUserCanAuthenticate( '<invalid>' ) );
		$this->assertFalse( $provider->testUserCanAuthenticate( 'DoesNotExist' ) );

		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [
				'user_newpassword' => PasswordFactory::newInvalidPassword()->toString(),
				'user_newpass_time' => null,
			] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();
		$this->assertFalse( $provider->testUserCanAuthenticate( $user->getName() ) );

		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_newpassword' => $pwhash, 'user_newpass_time' => null, ] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();
		$this->assertTrue( $provider->testUserCanAuthenticate( $user->getName() ) );
		$this->assertTrue( $provider->testUserCanAuthenticate( lcfirst( $user->getName() ) ) );

		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_newpassword' => $pwhash, 'user_newpass_time' => $dbw->timestamp( time() - 10 ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();
		$providerPriv->newPasswordExpiry = 100;
		$this->assertTrue( $provider->testUserCanAuthenticate( $user->getName() ) );
		$providerPriv->newPasswordExpiry = 1;
		$this->assertFalse( $provider->testUserCanAuthenticate( $user->getName() ) );

		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [
				'user_newpassword' => PasswordFactory::newInvalidPassword()->toString(),
				'user_newpass_time' => null,
			] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();
	}

	/**
	 * @dataProvider provideGetAuthenticationRequests
	 * @param string $action
	 * @param bool $registered
	 * @param array $expected
	 */
	public function testGetAuthenticationRequests( $action, bool $registered, $expected ) {
		$options = [ 'username' => $registered ? 'TestGetAuthenticationRequests' : null ];
		$actual = $this->getProvider( [ 'emailEnabled' => true ] )
			->getAuthenticationRequests( $action, $options );
		foreach ( $actual as $req ) {
			if ( $req instanceof TemporaryPasswordAuthenticationRequest && $req->password !== null ) {
				$req->password = 'random';
			}
		}
		$this->assertEquals( $expected, $actual );
	}

	public static function provideGetAuthenticationRequests() {
		$anon = false;
		$registered = true;

		return [
			[ AuthManager::ACTION_LOGIN, $anon, [
				new PasswordAuthenticationRequest
			] ],
			[ AuthManager::ACTION_LOGIN, $registered, [
				new PasswordAuthenticationRequest
			] ],
			[ AuthManager::ACTION_CREATE, $anon, [] ],
			[ AuthManager::ACTION_CREATE, $registered, [
				new TemporaryPasswordAuthenticationRequest( 'random' )
			] ],
			[ AuthManager::ACTION_LINK, $anon, [] ],
			[ AuthManager::ACTION_LINK, $registered, [] ],
			[ AuthManager::ACTION_CHANGE, $anon, [
				new TemporaryPasswordAuthenticationRequest( 'random' )
			] ],
			[ AuthManager::ACTION_CHANGE, $registered, [
				new TemporaryPasswordAuthenticationRequest( 'random' )
			] ],
			[ AuthManager::ACTION_REMOVE, $anon, [
				new TemporaryPasswordAuthenticationRequest
			] ],
			[ AuthManager::ACTION_REMOVE, $registered, [
				new TemporaryPasswordAuthenticationRequest
			] ],
		];
	}

	public function testAuthentication() {
		$user = self::getMutableTestUser()->getUser();

		$password = 'TemporaryPassword';
		$hash = ':A:' . md5( $password );
		$dbw = $this->getDb();
		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_newpassword' => $hash, 'user_newpass_time' => $dbw->timestamp( time() - 10 ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();

		$req = new PasswordAuthenticationRequest();
		$req->action = AuthManager::ACTION_LOGIN;
		$reqs = [ PasswordAuthenticationRequest::class => $req ];

		$provider = $this->getProvider();
		$providerPriv = TestingAccessWrapper::newFromObject( $provider );

		$providerPriv->newPasswordExpiry = 100;

		// General failures
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAuthentication( [] )
		);

		$req->username = 'foo';
		$req->password = null;
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAuthentication( $reqs )
		);

		$req->username = null;
		$req->password = 'bar';
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAuthentication( $reqs )
		);

		$req->username = '<invalid>';
		$req->password = 'WhoCares';
		$ret = $provider->beginPrimaryAuthentication( $reqs );
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAuthentication( $reqs )
		);

		$req->username = 'DoesNotExist';
		$req->password = 'DoesNotExist';
		$ret = $provider->beginPrimaryAuthentication( $reqs );
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAuthentication( $reqs )
		);

		// Validation failure
		$req->username = $user->getName();
		$req->password = $password;
		$this->validity = Status::newFatal( 'arbitrary-failure' );
		$ret = $provider->beginPrimaryAuthentication( $reqs );
		$this->assertEquals(
			AuthenticationResponse::FAIL,
			$ret->status
		);
		$this->assertEquals(
			'fatalpassworderror',
			$ret->message->getKey()
		);
		$this->assertEquals(
			'arbitrary-failure',
			$ret->message->getParams()[0]->getKey()
		);

		// Successful auth
		$this->manager->removeAuthenticationSessionData( null );
		$this->validity = Status::newGood();
		$this->assertEquals(
			AuthenticationResponse::newPass( $user->getName() ),
			$provider->beginPrimaryAuthentication( $reqs )
		);
		$this->assertNotNull( $this->manager->getAuthenticationSessionData( 'reset-pass' ) );

		$this->manager->removeAuthenticationSessionData( null );
		$this->validity = Status::newGood();
		$req->username = lcfirst( $user->getName() );
		$this->assertEquals(
			AuthenticationResponse::newPass( $user->getName() ),
			$provider->beginPrimaryAuthentication( $reqs )
		);
		$this->assertNotNull( $this->manager->getAuthenticationSessionData( 'reset-pass' ) );
		$req->username = $user->getName();

		// Expired password
		$providerPriv->newPasswordExpiry = 1;
		$ret = $provider->beginPrimaryAuthentication( $reqs );
		$this->assertEquals(
			AuthenticationResponse::FAIL,
			$ret->status
		);
		$this->assertEquals(
			'wrongpassword',
			$ret->message->getKey()
		);

		// Bad password
		$providerPriv->newPasswordExpiry = 100;
		$this->validity = Status::newGood();
		$req->password = 'Wrong';
		$ret = $provider->beginPrimaryAuthentication( $reqs );
		$this->assertEquals(
			AuthenticationResponse::FAIL,
			$ret->status
		);
		$this->assertEquals(
			'wrongpassword',
			$ret->message->getKey()
		);
	}

	/**
	 * @dataProvider provideProviderAllowsAuthenticationDataChange
	 *
	 * @param string $type
	 * @param callable $usernameGetter Function that takes the username of a sysop user and returns the username to
	 *  use for testing.
	 * @param Status $validity Result of the password validity check
	 * @param StatusValue $expect1 Expected result with $checkData = false
	 * @param StatusValue $expect2 Expected result with $checkData = true
	 */
	public function testProviderAllowsAuthenticationDataChange( $type, callable $usernameGetter,
		Status $validity,
		StatusValue $expect1, StatusValue $expect2
	) {
		$user = $usernameGetter( $this->getTestSysop()->getUserIdentity()->getName() );
		if ( $type === PasswordAuthenticationRequest::class ||
			$type === TemporaryPasswordAuthenticationRequest::class
		) {
			$req = new $type();
			$req->password = 'NewPassword';
		} else {
			$req = $this->createMock( $type );
		}
		$req->action = AuthManager::ACTION_CHANGE;
		$req->username = $user;

		$provider = $this->getProvider();
		$this->validity = $validity;
		$this->assertEquals( $expect1, $provider->providerAllowsAuthenticationDataChange( $req, false ) );
		$this->assertEquals( $expect2, $provider->providerAllowsAuthenticationDataChange( $req, true ) );
	}

	public static function provideProviderAllowsAuthenticationDataChange() {
		$err = StatusValue::newGood();
		$err->error( 'arbitrary-warning' );

		return [
			[
				AuthenticationRequest::class,
				static fn ( $sysopUsername ) => $sysopUsername,
				Status::newGood(),
				StatusValue::newGood( 'ignored' ),
				StatusValue::newGood( 'ignored' )
			],
			[
				PasswordAuthenticationRequest::class,
				static fn ( $sysopUsername ) => $sysopUsername,
				Status::newGood(),
				StatusValue::newGood( 'ignored' ),
				StatusValue::newGood( 'ignored' )
			],
			[
				TemporaryPasswordAuthenticationRequest::class,
				static fn ( $sysopUsername ) => $sysopUsername,
				Status::newGood(),
				StatusValue::newGood(),
				StatusValue::newGood()
			],
			[
				TemporaryPasswordAuthenticationRequest::class,
				'lcfirst',
				Status::newGood(),
				StatusValue::newGood(),
				StatusValue::newGood()
			],
			[
				TemporaryPasswordAuthenticationRequest::class,
				static fn ( $sysopUsername ) => $sysopUsername,
				Status::wrap( $err ),
				StatusValue::newGood(),
				$err
			],
			[
				TemporaryPasswordAuthenticationRequest::class,
				static fn ( $sysopUsername ) => $sysopUsername,
				Status::newFatal( 'arbitrary-error' ),
				StatusValue::newGood(),
				StatusValue::newFatal( 'arbitrary-error' )
			],
			[
				TemporaryPasswordAuthenticationRequest::class,
				static fn () => 'DoesNotExist',
				Status::newGood(),
				StatusValue::newGood(),
				StatusValue::newGood( 'ignored' )
			],
			[
				TemporaryPasswordAuthenticationRequest::class,
				static fn () => '<invalid>',
				Status::newGood(),
				StatusValue::newGood(),
				StatusValue::newGood( 'ignored' )
			],
		];
	}

	/**
	 * @dataProvider provideProviderChangeAuthenticationData
	 * @param string $type
	 * @param bool $changed
	 */
	public function testProviderChangeAuthenticationData( $type, $changed ) {
		$user = $this->getTestSysop()->getUserIdentity()->getName();
		$oldpass = 'OldTempPassword';
		$newpass = 'NewTempPassword';

		$dbw = $this->getDb();
		$oldHash = $dbw->newSelectQueryBuilder()
			->select( 'user_newpassword' )
			->from( 'user' )
			->where( [ 'user_name' => $user ] )
			->fetchField();
		$cb = new ScopedCallback( static function () use ( $dbw, $user, $oldHash ) {
			$dbw->newUpdateQueryBuilder()
				->update( 'user' )
				->set( [ 'user_newpassword' => $oldHash ] )
				->where( [ 'user_name' => $user ] )
				->execute();
		} );

		$hash = ':A:' . md5( $oldpass );
		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_newpassword' => $hash, 'user_newpass_time' => $dbw->timestamp( time() + 10 ) ] )
			->where( [ 'user_name' => $user ] )
			->execute();

		$provider = $this->getProvider();

		$loginReq = new PasswordAuthenticationRequest();
		$loginReq->action = AuthManager::ACTION_CHANGE;
		$loginReq->username = $user;
		$loginReq->password = $oldpass;
		$loginReqs = [ PasswordAuthenticationRequest::class => $loginReq ];
		$this->assertEquals(
			AuthenticationResponse::newPass( $user ),
			$provider->beginPrimaryAuthentication( $loginReqs )
		);

		if ( $type === PasswordAuthenticationRequest::class ||
			$type === TemporaryPasswordAuthenticationRequest::class
		) {
			$changeReq = new $type();
			$changeReq->password = $newpass;
		} else {
			$changeReq = $this->createMock( $type );
		}
		$changeReq->action = AuthManager::ACTION_CHANGE;
		$changeReq->username = $user;
		$resetMailer = $this->hookMailer();
		$provider->providerChangeAuthenticationData( $changeReq );
		ScopedCallback::consume( $resetMailer );

		$loginReq->password = $oldpass;
		$ret = $provider->beginPrimaryAuthentication( $loginReqs );
		$this->assertEquals(
			AuthenticationResponse::FAIL,
			$ret->status,
			'old password should fail'
		);
		$this->assertEquals(
			'wrongpassword',
			$ret->message->getKey(),
			'old password should fail'
		);

		$loginReq->password = $newpass;
		$ret = $provider->beginPrimaryAuthentication( $loginReqs );
		if ( $changed ) {
			$this->assertEquals(
				AuthenticationResponse::newPass( $user ),
				$ret,
				'new password should pass'
			);
			$this->assertNotNull(
				$dbw->newSelectQueryBuilder()
					->select( 'user_newpass_time' )
					->from( 'user' )
					->where( [ 'user_name' => $user ] )
					->fetchField()
			);
		} else {
			$this->assertEquals(
				AuthenticationResponse::FAIL,
				$ret->status,
				'new password should fail'
			);
			$this->assertEquals(
				'wrongpassword',
				$ret->message->getKey(),
				'new password should fail'
			);
			$this->assertNull(
				$dbw->newSelectQueryBuilder()
					->select( 'user_newpass_time' )
					->from( 'user' )
					->where( [ 'user_name' => $user ] )
					->fetchField()
			);
		}
	}

	public static function provideProviderChangeAuthenticationData() {
		return [
			[ AuthenticationRequest::class, false ],
			[ PasswordAuthenticationRequest::class, false ],
			[ TemporaryPasswordAuthenticationRequest::class, true ],
		];
	}

	public function testProviderChangeAuthenticationDataEmail() {
		$user = self::getMutableTestUser()->getUser();

		$dbw = $this->getDb();
		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_newpass_time' => $dbw->timestamp( time() - 5 * 3600 ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();

		$req = TemporaryPasswordAuthenticationRequest::newRandom();
		$req->username = $user->getName();
		$req->mailpassword = true;

		$provider = $this->getProvider( [ 'emailEnabled' => false ] );
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newFatal( 'passwordreset-emaildisabled' ), $status );

		$provider = $this->getProvider( [
			'emailEnabled' => true, 'passwordReminderResendTime' => 10
		] );
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newFatal( 'throttled-mailpassword', 10 ), $status );

		$provider = $this->getProvider( [
			'emailEnabled' => true, 'passwordReminderResendTime' => 3
		] );
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertFalse( $status->hasMessage( 'throttled-mailpassword' ) );

		$dbw->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_newpass_time' => $dbw->timestamp( time() + 5 * 3600 ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->execute();
		$provider = $this->getProvider( [
			'emailEnabled' => true, 'passwordReminderResendTime' => 0
		] );
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertFalse( $status->hasMessage( 'throttled-mailpassword' ) );

		$req->caller = null;
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newFatal( 'passwordreset-nocaller' ), $status );

		$req->caller = '127.0.0.256';
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newFatal( 'passwordreset-nosuchcaller', '127.0.0.256' ),
			$status );

		$req->caller = '<Invalid>';
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newFatal( 'passwordreset-nosuchcaller', '<Invalid>' ),
			$status );

		$req->caller = '127.0.0.1';
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newGood(), $status );

		$req->caller = $user->getName();
		$status = $provider->providerAllowsAuthenticationDataChange( $req, true );
		$this->assertEquals( StatusValue::newGood(), $status );

		$mailed = false;
		$resetMailer = $this->hookMailer( function ( $headers, $to, $from, $subject, $body )
			use ( &$mailed, $req, $user )
		{
			$mailed = true;
			$this->assertSame( $user->getEmail(), $to[0]->address );
			$this->assertStringContainsString( $req->password, $body );
			return false;
		} );
		$provider->providerChangeAuthenticationData( $req );
		ScopedCallback::consume( $resetMailer );
		$this->assertTrue( $mailed );

		$priv = TestingAccessWrapper::newFromObject( $provider );
		$req->username = '<invalid>';
		$status = $priv->sendPasswordResetEmail( $req );
		$this->assertEquals( Status::newFatal( 'noname' ), $status );
	}

	public function testTestForAccountCreation() {
		$user = User::newFromName( 'foo' );
		$req = new TemporaryPasswordAuthenticationRequest();
		$req->username = 'Foo';
		$req->password = 'Bar';
		$reqs = [ TemporaryPasswordAuthenticationRequest::class => $req ];

		$provider = $this->getProvider();
		$this->assertEquals(
			StatusValue::newGood(),
			$provider->testForAccountCreation( $user, $user, [] ),
			'No password request'
		);

		$this->assertEquals(
			StatusValue::newGood(),
			$provider->testForAccountCreation( $user, $user, $reqs ),
			'Password request, validated'
		);

		$this->validity->error( 'arbitrary warning' );
		$expect = StatusValue::newGood();
		$expect->error( 'arbitrary warning' );
		$this->assertEquals(
			$expect,
			$provider->testForAccountCreation( $user, $user, $reqs ),
			'Password request, not validated'
		);
	}

	public function testAccountCreation() {
		$resetMailer = $this->hookMailer();

		$user = User::newFromName( 'Foo' );

		$req = new TemporaryPasswordAuthenticationRequest();
		$reqs = [ TemporaryPasswordAuthenticationRequest::class => $req ];

		$authreq = new PasswordAuthenticationRequest();
		$authreq->action = AuthManager::ACTION_CREATE;
		$authreqs = [ PasswordAuthenticationRequest::class => $authreq ];

		$provider = $this->getProvider();

		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAccountCreation( $user, $user, [] )
		);

		$req->username = 'foo';
		$req->password = null;
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAccountCreation( $user, $user, $reqs )
		);

		$req->username = null;
		$req->password = 'bar';
		$this->assertEquals(
			AuthenticationResponse::newAbstain(),
			$provider->beginPrimaryAccountCreation( $user, $user, $reqs )
		);

		$req->username = 'foo';
		$req->password = 'bar';

		$expect = AuthenticationResponse::newPass( 'Foo' );
		$expect->createRequest = clone $req;
		$expect->createRequest->username = 'Foo';
		$this->assertEquals( $expect, $provider->beginPrimaryAccountCreation( $user, $user, $reqs ) );
		$this->assertNull( $this->manager->getAuthenticationSessionData( 'no-email' ) );

		$user = self::getMutableTestUser()->getUser();
		$req->username = $authreq->username = $user->getName();
		$req->password = $authreq->password = 'NewPassword';
		$expect = AuthenticationResponse::newPass( $user->getName() );
		$expect->createRequest = $req;

		$res2 = $provider->beginPrimaryAccountCreation( $user, $user, $reqs );
		$this->assertEquals( $expect, $res2 );

		$ret = $provider->beginPrimaryAuthentication( $authreqs );
		$this->assertEquals( AuthenticationResponse::FAIL, $ret->status );

		$this->assertSame( null, $provider->finishAccountCreation( $user, $user, $res2 ) );

		$ret = $provider->beginPrimaryAuthentication( $authreqs );
		$this->assertEquals( AuthenticationResponse::PASS, $ret->status, 'new password is set' );
	}

	public function testAccountCreationEmail() {
		$creator = User::newFromName( 'Foo' );

		$user = self::getMutableTestUser()->getUser();
		$user->setEmail( '' );

		$req = TemporaryPasswordAuthenticationRequest::newRandom();
		$req->username = $user->getName();
		$req->mailpassword = true;

		$provider = $this->getProvider( [ 'emailEnabled' => false ] );
		$status = $provider->testForAccountCreation( $user, $creator, [ $req ] );
		$this->assertEquals( StatusValue::newFatal( 'emaildisabled' ), $status );

		$provider = $this->getProvider( [ 'emailEnabled' => true ] );
		$status = $provider->testForAccountCreation( $user, $creator, [ $req ] );
		$this->assertEquals( StatusValue::newFatal( 'noemailcreate' ), $status );

		$user->setEmail( 'test@localhost.localdomain' );
		$status = $provider->testForAccountCreation( $user, $creator, [ $req ] );
		$this->assertEquals( StatusValue::newGood(), $status );

		$mailed = false;
		$resetMailer = $this->hookMailer( function ( $headers, $to, $from, $subject, $body )
			use ( &$mailed, $req )
		{
			$mailed = true;
			$this->assertSame( 'test@localhost.localdomain', $to[0]->address );
			$this->assertStringContainsString( $req->password, $body );
			return false;
		} );

		$expect = AuthenticationResponse::newPass( $user->getName() );
		$expect->createRequest = clone $req;
		$expect->createRequest->username = $user->getName();
		$res = $provider->beginPrimaryAccountCreation( $user, $creator, [ $req ] );
		$this->assertEquals( $expect, $res );
		$this->assertTrue( $this->manager->getAuthenticationSessionData( 'no-email' ) );
		$this->assertFalse( $mailed );

		$this->assertSame( 'byemail', $provider->finishAccountCreation( $user, $creator, $res ) );
		$this->assertTrue( $mailed );

		ScopedCallback::consume( $resetMailer );
		$this->assertTrue( $mailed );
	}

}
