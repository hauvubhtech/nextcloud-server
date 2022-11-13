<?php
/**
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\DAV\Tests\unit\CalDAV\BirthdayCalendar;

use OCA\DAV\CalDAV\AppCalendar\AppCalendar;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\Constants;
use Test\TestCase;

class AppCalendarTest extends TestCase {
	private AppCalendar $appCalendar;
	private AppCalendar $writeableAppCalendar;

	private $principal = 'principals/users/foo';

	protected function setUp(): void {
		parent::setUp();

		$calendar = $this->getMock(ICalendar::class);
		$writeableCalendar = $this->getMock(ICreateFromString::class);
		$writeableCalendar->method('getPermissions')
			->will($this->returnArgument(Constants::PERMISSION_READ | Constants::PERMISSION_CREATE));
		$this->appCalendar = $this->createMock(AppCalendar::class, ['dav-wrapper', $calendar, $this->principal]);
		$this->writeableAppCalendar = $this->createMock(AppCalendar::class, ['dav-wrapper', $writeableCalendar, $this->principal]);
	}

	public function testGetPrincipal():void {
		// Check that the correct name is returned
		$this->assertEquals($this->principal, $this->appCalendar->getOwner());
		$this->assertEquals($this->principal, $this->writeableAppCalendar->getOwner());
	}

	public function testSetACL(): void {
		$this->expectException(\Sabre\DAV\Exception\Forbidden::class);
		$this->expectExceptionMessage('Setting ACL is not supported on this node');

		$this->appCalendar->setACL([]);
	}

	public function testDelete(): void {
		$this->expectException(\Sabre\DAV\Exception\Forbidden::class);
		$this->expectExceptionMessage('Deleting an entry is not implemented');

		$this->appCalendar->delete();
	}

	public function testCreateFile() {
		// If writing is not supported
		$this->expectException(\Sabre\DAV\Exception\Forbidden::class);
		$this->expectExceptionMessage('Creating a new entry is not implemented');

		$this->appCalendar->createFile('some-name', 'data');

		// If write is supported
		$this->assertNull($this->writeableAppCalendar->createFile('some-name', 'data'));
	}

	public function testGetACL():void {
		$expectedRO = [
			[
				'privilege' => '{DAV:}read',
				'principal' => $this->principal,
				'protected' => true,
			],
			[
				'privilege' => '{DAV:}write-properties',
				'principal' => $this->principal,
				'protected' => true,
			]
		];
		$expectedRW = $expectedRO;
		$expectedRW[] = [
			'privilege' => '{DAV:}write',
			'principal' => $this->principal,
			'protected' => true,
		];
		
		// Check that the correct ACL is returned (default be only readable)
		$this->assertEquals($expectedRO, $this->appCalendar->getACL());
		$this->assertEquals($expectedRW, $this->writeableAppCalendar->getACL());
	}
}
