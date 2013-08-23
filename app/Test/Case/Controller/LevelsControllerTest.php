<?php
App::uses('Level', 'Model');

/**
 * Create .zip file from an array of the form
 * @code
 * array(
 *   'file1.txt' => 'contents of file one',
 *   'file2.txt' => 'contents of file two',
 *   'dir1' => array(
 *     'file3.txt' => 'contents of file three'
 *     )
 *   )
 * @endcode
 */
function buildZip($files, $zip = null, $base = '') {
  if($zip === null) {
    $zipName = tempnam(sys_get_temp_dir(), '') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipName, ZipArchive::CREATE);
    buildZip($files, $zip);
    $zip->close();
    return $zipName;
  } else {
    foreach ($files as $filename => $contents) {
      if(is_array($contents)) {
        $zip->addEmptyDir($base . $filename);
        buildZip($contents, $zip, $base . $filename . '/');
      } else {
        $zip->addFromString($filename, $contents);
      }
    }
  }
}

class LevelsControllerTest extends ControllerTestCase {
  public $fixtures = array('app.level', 'app.user', 'app.user_group', 'app.rating', 'app.tag', 'app.comment', 'app.levels_tag');

  // configures a mock as fixture user 'bob'
  function mockAsBob($mockLevel = false) {
    // mock Auth component
    $options = array(
        'methods' => array('getUploadFilename', 'isAdmin'),
        'components' => array('Auth' => array('user', 'loggedIn', 'login'))
    );

    // mock Level#save, as well
    if($mockLevel) {
      $options['models'] = array('Level' => array('save'));
    }

    $Levels = $this->generate('Levels', $options);

    $Levels->Auth
      ->staticExpects($this->any())
      ->method('user')
      ->will($this->returnValue(2));

    $Levels->Auth
      ->expects($this->any())
      ->method('loggedIn')
      ->will($this->returnValue(true));

    $Levels->Auth
      ->expects($this->any())
      ->method('login')
      ->will($this->returnValue(true));

    $Levels
      ->expects($this->any())
      ->method('isAdmin')
      ->will($this->returnValue(true));

    return $Levels;
  }

  // configures a mock as fixture user 'alice'
  function mockAsAlice() {
    // mock Auth component
    $options = array(
        'methods' => array('getUploadFilename'),
        'components' => array('Auth' => array('user', 'loggedIn', 'login'))
    );

    $Levels = $this->generate('Levels', $options);

    $Levels->Auth
      ->staticExpects($this->any())
      ->method('user')
      ->will($this->returnValue(1));

    $Levels->Auth
      ->expects($this->any())
      ->method('loggedIn')
      ->will($this->returnValue(true));

    $Levels->Auth
      ->expects($this->any())
      ->method('login')
      ->will($this->returnValue(true));

    return $Levels;
  }

  // configures a mock as an unauthenticated user
  function mockAsUnauthenticated() {
    $Levels = $this->generate('Levels', array(
      'components' => array(
        'Auth' => array(
          'loggedIn'
        )
      )
    ));

    $Levels->Auth
      ->expects($this->any())
      ->method('loggedIn')
      ->will($this->returnValue(false));
  }

  public function setUp() {
    parent::setUp();
    $this->Level = ClassRegistry::init('Level');
    Configure::write('App.user_db_config', 'test_forum');
  }

  public function testIndex() {
    $result = $this->testAction('/levels/index/', array('return' => 'vars'));
  }

  public function testEditNoId() {
    $this->setExpectedException('BadRequestException');
    $this->testAction('/levels/edit/', array('return' => 'vars'));
  }

  public function testEditBadId() {
    $this->setExpectedException('BadRequestException');
    $this->testAction('/levels/edit/-1', array('return' => 'vars'));
  }

  public function testEditNotLoggedIn() {
    $this->mockAsUnauthenticated();

    $this->setExpectedException('ForbiddenException');
    $this->testAction('/levels/edit/1', array('return' => 'vars'));
  }

  public function testEditLevelOwnedByOtherUser() {
    $this->mockAsAlice();

    $level = $this->Level->findByUserId(2);
    $this->setExpectedException('ForbiddenException');
    $this->testAction('/levels/edit/' . $level['Level']['id']);
  }

  public function testAdminEdit() {
    $this->mockAsBob();

    // alice's level
    $level = $this->Level->findById(1);

    $data = array(
      'Level' => array(
        'content' => $level['Level']['content'] . "\nBlah blah",
        'levelgen' => $level['Level']['levelgen'] . "\nLevelgen Addendum"
        )
      );

    $this->assertNotContains('Blah blah', $level['Level']['content']);
    $this->assertNotContains('Levelgen Addendum', $level['Level']['levelgen']);
    $result = $this->testAction('/levels/edit/' . $level['Level']['id'], array('data' => $data, 'return' => 'vars'));
    $level = $this->Level->findById(1);
    $this->assertContains('Blah blah', $level['Level']['content']);
    $this->assertContains('Levelgen Addendum', $level['Level']['levelgen']);
    $this->assertEquals(1, $level['Level']['user_id']);
    $this->assertEquals('alice', $level['Level']['author']);
  }

  public function testEditSuccess() {
    $this->mockAsBob();
    $level = $this->Level->findByUserId(2);

    $levelData = array(
      'name' => 'level' . time(),
      'content' => 'LevelName test',
      'levelgen' => '',
      'description' => 'descriptive',
    );

    $result = $this->testAction('/levels/edit/' . $level['Level']['id'], array(
      'data' => array('Level' => $levelData),
      'method' => 'post',
      'return' => 'vars'
    ));

    $level = $this->Level->findByUserId(2);
    $this->assertStringEndsWith('/levels/view/' . $level['Level']['id'], $this->headers['Location']);
  }

  public function testEditFailure() {
    $Levels = $this->mockAsBob(true);

    $Levels->Level
      ->expects($this->once())
      ->method('save')
      ->will($this->returnValue(false));

    $level = $this->Level->findByUserId(2);

    $levelData = array(
      'name' => 'level' . time(),
      'content' => 'empty (more or less)',
      'levelgen' => '',
      'description' => 'descriptive'
    );

    $result = $this->testAction('/levels/edit/' . $level['Level']['id'], array(
      'data' => array('Level' => $levelData),
      'method' => 'post',
      'return' => 'vars'
    ));

    // should not redirect
    $this->assertArrayNotHasKey('Location', $this->headers);
    // should not update level
    $resultLevel = $Levels->Level->findById($level['Level']['id']);
    $this->assertNotEquals($levelData, $resultLevel['Level']);
  }

  public function testEditWithoutData() {
    $this->mockAsBob();

    $level = $this->Level->findById(3);
    $result = $this->testAction('/levels/edit/' . $level['Level']['id'], array('method' => 'get', 'return' => 'view'));
    // no redirect
    $this->assertArrayNotHasKey('Location', $this->headers);
    // puts level data into the form
    $this->assertRegexp("/uploaded by bob/", $result);
  }

  public function testView() {
    $this->mockAsBob();
    $level = $this->Level->findByUserId(2);
    $result = $this->testAction('/levels/view/' . $level['Level']['id'], array(
      'return' => 'vars'
    ));
    $this->assertEquals($level, $result['level']);
  }

  public function testRate() {
    $this->mockAsBob();

    $level = $this->Level->findById(1);
    $oldTime = $level['Level']['last_updated'];
    $result = $this->testAction('/levels/rate/' . $level['Level']['id'] . '/1', array(
      'return' => 'vars'
    ));

    $updatedLevel = $this->Level->findById(1);
    $newTime = $updatedLevel['Level']['last_updated'];
    $this->assertNotEquals($level['Level']['rating'], $updatedLevel['Level']['rating']);
    $this->assertEquals($oldTime, $newTime);
  }
 
  public function testRateUpDown() {
    $this->mockAsBob();

    $level = $this->Level->findById(1);
    $oldTime = $level['Level']['last_updated'];
    $result = $this->testAction('/levels/rate/' . $level['Level']['id'] . '/up', array(
      'return' => 'vars'
    ));

    $this->mockAsBob();

    $updatedLevel = $this->Level->findById(1);
    $newTime = $updatedLevel['Level']['last_updated'];
    $this->assertEquals($level['Level']['rating'] + 1, $updatedLevel['Level']['rating']);
    $this->assertEquals($oldTime, $newTime);

    $result = $this->testAction('/levels/rate/' . $level['Level']['id'] . '/down', array(
      'return' => 'vars'
    ));

    $updatedLevel = $this->Level->findById(1);
    $this->assertEquals($level['Level']['rating'] - 1, $updatedLevel['Level']['rating']);
  }

  public function testRateFail() {
    $this->setExpectedException('BadRequestException');
    $this->mockAsBob();
    $Rating = $this->getMockForModel('Rating', array('save'));
    $Rating
      ->expects($this->once())
      ->method('save')
      ->will($this->returnValue(false));

    $level = $this->Level->findById(1);
    $this->testAction('/levels/rate/' . $level['Level']['id'] . '/1', array(
      'return' => 'vars'
    ));

    $updatedLevel = $this->Level->findById(1);
    $this->assertEquals($level, $updatedLevel);
  }

  /*
   * flakes because Session doesn't work
   *
  public function testRateWithAuthData() {
    $data = array(
        'username' => 'bob',
        'user_password' => 'password',
    );

    $level = $this->Level->findById(1);
    $oldRating = $level["Level"]["rating"];

    $result = $this->testAction('/levels/rate/1/up', array(
      'data' => array('User' => $data)
    ));

    $level = $this->Level->findById(1);
    $newRating = $level["Level"]["rating"];

    $this->assertEquals($oldRating + 1, $newRating);
  }
   */

  public function testRateNonExistentLevel() {
    $this->setExpectedException('BadRequestException');
    $this->mockAsBob();

    $level = $this->Level->findById(1);
    $oldRating = $level["Level"]["rating"];

    $result = $this->testAction('/levels/rate/1337/up');
  }

  public function testAdd() {
    $this->mockAsBob();

    $levelData = array(
      'name' => 'level' . time(),
      'content' => 'LevelName test',
      'levelgen' => '',
      'description' => 'descriptive'
    );

    $oldCount = $this->Level->find('count');
    $this->testAction('/levels/add/', array(
      'data' => array('Level' => $levelData),
      'method' => 'post'
    ));
    $newCount = $this->Level->find('count');

    $this->assertEquals($oldCount + 1, $newCount);
  }

  public function testAddFail() {
    $Levels = $this->mockAsBob(true);
    $Levels->Level
      ->expects($this->once())
      ->method('save')
      ->will($this->returnValue(false));

    $levelData = array(
      'name' => 'level' . time(),
      'content' => 'LevelName test',
      'levelgen' => '',
      'description' => 'descriptive'
    );

    $oldCount = $this->Level->find('count');
    $this->testAction('/levels/add/', array(
      'data' => array('Level' => $levelData),
      'method' => 'post'
    ));
    $newCount = $this->Level->find('count');

    $this->assertEquals($oldCount, $newCount);
  }

  public function testRaw() {
    $this->mockAsUnauthenticated();
    $level = $this->Level->findById(1);
    $result = $this->testAction('/levels/raw/' . $level['Level']['id'], array('return' => 'contents'));
    $this->assertEquals($level['Level']['content'], $result);

    $result = $this->testAction('/levels/raw/' . $level['Level']['id'] . '/levelgen', array('return' => 'contents'));
    $this->assertEquals('-- ' . $level['Level']['levelgen_filename'] . "\r\n" . $level['Level']['levelgen'], $result);
  }

  public function testRawBadDisplayMode() {
    $this->setExpectedException('BadRequestException');
    $level = $this->Level->find('first');
    $result = $this->testAction('/levels/raw/' . $level['Level']['id'] . '/thisDisplayModeDoesNotExist', array('return' => 'contents'));
    $this->assertEquals($level['Level']['content'], $result);
  }

  public function testUploadNewLevel() {
    $this->mockAsBob();
    $levelData = array(
        'username' => 'bob',
        'password' => 'password',
        'content' => 'LevelName Level II',
        'user_id' => 2
    );

    $oldCount = $this->Level->find('count');
    $result = $this->testAction('/levels/upload/', array(
      'data' => array('Level' => $levelData),
      'method' => 'post'
    ));
    $newCount = $this->Level->find('count');

    $this->assertEquals($oldCount + 1, $newCount);
  }

  public function testUploadOldLevel() {
    $this->mockAsBob();
    $levelData = array(
        "content" => "LevelName Updated Level\nLevelDatabaseId 2",
    );

    $oldCount = $this->Level->find('count');
    $result = $this->testAction('/levels/upload/', array(
      'data' => array('Level' => $levelData),
      'method' => 'post'
    ));

    $newCount = $this->Level->find('count');
    $level = $this->Level->findById(2);
    $this->assertEquals($oldCount, $newCount);
    $this->assertEquals('Updated Level', $level['Level']['name']);
  }

  public function testDeleteSuccess() {
    $this->mockAsBob();
    $result = $this->Level->save(array(
        "content" => "LevelName Dead Man",
        "user_id" => 2
    ));
    $oldCount = $this->Level->find('count');
    $this->testAction('/levels/delete/' . $result['Level']['id']);
    $newCount = $this->Level->find('count');
    $this->assertEquals($oldCount - 1, $newCount);
  }

  public function testDeleteUnownedLevel() {
    $this->mockAsUnauthenticated();
    $this->setExpectedException('ForbiddenException');

    $oldCount = $this->Level->find('count');
    $this->testAction('/levels/delete/2');
    $newCount = $this->Level->find('count');

    $this->assertEquals($oldCount, $newCount);
  }

  public function testAdminDelete() {
    $this->mockAsBob();

    $oldCount = $this->Level->find('count');
    $this->testAction('/levels/delete/1');
    $newCount = $this->Level->find('count');

    $this->assertEquals($oldCount - 1, $newCount);
  }

  /*
  public function testDownload() {
    $this->mockAsUnauthenticated();
    $level = $this->Level->findById(1);
    $result = $this->testAction('/levels/download/' . $level['Level']['id'], array('return' => 'contents'));
  }

  public function testDownloadCount() {
    $this->mockAsUnauthenticated();

    $level = $this->Level->findById(1);
    $oldCount = $level['Level']['downloads'];

    $this->testAction('/levels/download/' . $level['Level']['id']);

    $level = $this->Level->findById(1);
    $newCount = $level['Level']['downloads'];

    $this->assertGreaterThan($oldCount, $newCount);
  }
   */

  public function testRawIncrementsDownloadCount() {
    $this->mockAsUnauthenticated();

    $level = $this->Level->findById(1);
    $oldCount = $level['Level']['downloads'];

    $this->testAction('/levels/raw/' . $level['Level']['id']);

    $level = $this->Level->findById(1);
    $countAfterRawLevel = $level['Level']['downloads'];

    $this->testAction('/levels/raw/' . $level['Level']['id'] .'/levelgen');

    $level = $this->Level->findById(1);
    $countAfterRawLevelgen = $level['Level']['downloads'];

    // getting the raw level code increments download count
    $this->assertGreaterThan($oldCount, $countAfterRawLevelgen);
    // but getting the levelgen doesn't
    $this->assertEquals($countAfterRawLevelgen, $countAfterRawLevel);
  }
  
  public function testSearch() {
    $result = $this->testAction('/levels/search/name:bob\'s', array(
      'return' => 'vars'
    ));
    // matches "bob's level"
    $this->assertEqual(1, count($result['levels']));

    $result = $this->testAction('/levels/search/name:blah', array(
      'return' => 'vars'
    ));
    // no matches
    $this->assertEqual(0, count($result['levels']));
  }

  public function testMassUpload() {
    $Levels = $this->mockAsBob();

    // build a temporary zip file
    $files = array(
        'one.level' => 'LevelName mass_one',
        'dir' => array(
          'two.levelgen' => 'junk',
          'two.level' => "LevelName mass_two\nScript two",
          'dir' => array(
              'thr.level' => 'LevelName mass_three'
            )
          )
      );

    $zipName = buildZip($files);

    // mock our upload check method
    $Levels
      ->expects($this->any())
      ->method('getUploadFilename')
      ->will($this->returnValue($zipName));

    // set up the request data
    $data = array(
      'Level' => array(
        'zipFile' => array(
          'type' => 'application/zip',
          'tmp_name' => $zipName,
          'error' => 0,
          )
        )
      );

    $oldcount = $this->Level->find('count');
    $results = $this->testAction('/levels/massupload', array('data' => $data, 'return' => 'vars'));
    $this->assertEquals(3, sizeof($results['uploads']));
    foreach($results['uploads'] as $i => $result) {
      $this->assertEmpty($result['errors']);
    }
    $newcount = $this->Level->find('count');
    $this->assertEquals($oldcount + 3, $newcount);
  }
}
