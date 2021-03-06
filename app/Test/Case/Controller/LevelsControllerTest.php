<?php
App::uses('Level', 'Model');
App::uses('User', 'Model');

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
                $zip->addFromString($base . $filename, $contents);
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
                'methods' => array('getUploadFilename', 'isAdmin'),
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

        $Levels
        ->expects($this->any())
        ->method('isAdmin')
        ->will($this->returnValue(false));

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

    /**
     * Runs the massupload action, pretending to have uploaded a zip file defined
     * by `files`, which should be of the form:
     * @code
     *  array(
     *          'one.level' => 'file contents go here',
     *          'myDirectory' => array(
     *                  'two.levelgen' => '...',
     *                  'two.level' => '...',
     *                  'myInnerDirectory' => array(
     *                          'three.level' => '...'
     *                  )
     *          )
     *  );
     * @endcode
     *
     * Returns the `vars` of testAction
     */

    function _testMassUpload($files) {
        $Levels = $this->mockAsBob();

        $zipName = buildZip($files);

        // mock our upload check method
        $Levels
        ->expects($this->any())
        ->method('_isValidUpload')
        ->will($this->returnValue(true));

        // mock our upload filename getter
        $Levels
        ->expects($this->any())
        ->method('_getUploadFilename')
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

        return $this->testAction('/levels/massupload', array('data' => $data, 'return' => 'vars'));
    }

    public function setUp() {
        parent::setUp();
        $this->Level = ClassRegistry::init('Level');
        $this->User = ClassRegistry::init('User');
        Cache::clear();
    }

    public function testIndex() {
        // should be identical on subsequent requests without modifications
        $result = $this->testAction('/levels/index/', array('return' => 'view'));
        $result2 = $this->testAction('/levels/index/', array('return' => 'view'));
        $this->assertEquals($result2, $result);
        $this->assertContains('alice', $result);

        // but then we make a modification
        $level = $this->Level->touch(1);

        // and now it should be different
        $result3 = $this->testAction('/levels/index/', array('return' => 'view'));
        $this->assertNotEquals($result3, $result2);
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
        $Level = $this->mockAsAlice();
        $Level->isAdmin();

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
                        'author' => 'someone else',
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
        $this->assertEquals(1, $level['User']['user_id']);
        $this->assertEquals('someone else', $level['Level']['author']);
        $this->assertEquals('alice', $level['User']['username']);
    }

    public function testAdminEditAuthor() {
        $this->mockAsBob();

        $data = array(
                'Level' => array(
                        'author' => 'someone else'
                )
        );

        $this->testAction('/levels/edit/1', array('data' => $data));

        // author field should be modified
        $level = $this->Level->findById(1);
        $this->assertEquals('someone else', $level['Level']['author']);

        // author should by used as username
        $result = $this->testAction('/levels/view/1', array('data' => $data, 'return' => 'view'));
        $this->assertContains('someone else', $result);
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
        // view should typically contain the username of the owner
        $result = $this->testAction('/levels/view/2', array('return' => 'view'));
        $this->assertTag(array(
                'attributes' => array('class' => 'author'),
                'content' => 'bob'
            ), $result);

        // view should not change when some other level is modified
        $this->Level->touch(3);
        $result2 = $this->testAction('/levels/view/2', array('return' => 'view'));
        $this->assertEquals($result, $result2);

        // but it should be different if the specified level is modified
        $this->Level->id = 2;
        $this->Level->saveField('rating', 1337);

        $result3 = $this->testAction('/levels/view/2', array('return' => 'view'));
        $this->assertNotEquals($result2, $result3);
    }

    public function testViewDisappears() {
        // view should be not found after the level is deleted
        $this->testAction('/levels/view/2');
        $this->Level->delete(2);

        $this->setExpectedException('NotFoundException');
        $this->testAction('/levels/view/2');
    }

    public function testViewNonExistantLevel() {
        $this->setExpectedException('NotFoundException');
        $result = $this->testAction('/levels/view/1337');
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

    public function testDownload() {
        $this->mockAsUnauthenticated();
        $level = $this->Level->findById(1);
        ob_start();
        $result = $this->testAction('/levels/download/' . $level['Level']['id'], array('return' => 'contents'));
    }

    public function testDownloadCount() {
        $this->mockAsUnauthenticated();

        $level = $this->Level->findById(1);
        $oldCount = $level['Level']['downloads'];

        ob_start();
        $this->testAction('/levels/download/' . $level['Level']['id']);

        $level = $this->Level->findById(1);
        $newCount = $level['Level']['downloads'];

        $this->assertGreaterThan($oldCount, $newCount);
    }

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
        // build a temporary zip file
        $files = array(
                'one.level' => 'LevelName mass_one',
                'dir2' => array(
                        'two.levelgen' => 'wrong'
                ),
                'dir' => array(
                        'two.levelgen' => 'right',
                        'two.level' => "LevelName mass_two\nScript two\n",
                        'dir' => array(
                                'thr.level' => 'LevelName mass_three'
                        )
                )
        );

        $oldcount = $this->Level->find('count');

        $results = $this->_testMassUpload($files);

        $this->assertEquals(3, sizeof($results['uploads']));
        foreach($results['uploads'] as $i => $result) {
            $this->assertEmpty($result['errors']);
        }
        $newcount = $this->Level->find('count');
        $this->assertEquals($oldcount + 3, $newcount);

        $fileWithLevelgen = $this->Level->findByName('mass_two');
        $this->assertEquals($fileWithLevelgen['Level']['levelgen'], 'right');
    }

    public function testMassUploadWithMacOsxDirectory() {
        // build a temporary zip file
        $files = array(
                'empty_dir' => array(),
                '__MACOSX' => array(
                        'test.level' => "LevelName Test"
                ),
                'another_dir' => array(
                    'notalevel.txt' => 'hi!'
                )
        );

        $oldcount = $this->Level->find('count');
        $results = $this->_testMassUpload($files);
        $this->assertEquals(1, sizeof($results['uploads']));
        foreach($results['uploads'] as $i => $result) {
            $this->assertNotEmpty($result['warnings']);
        }
        $newcount = $this->Level->find('count');
        $this->assertEquals($oldcount, $newcount);
    }

    public function testMassUploadWithMissingLevelgen() {
        // build a temporary zip file
        $files = array(
                'missing_levelgen.level' => "LevelName needs_a_levelgen\nScript does_not_exist\n"
        );

        $oldcount = $this->Level->find('count');
        $results = $this->_testMassUpload($files);
        $this->assertEquals(1, sizeof($results['uploads']));
        foreach($results['uploads'] as $i => $result) {
            $this->assertNotEmpty($result['errors']);
        }
        $newcount = $this->Level->find('count');
        $this->assertEquals($oldcount, $newcount);
    }
}
