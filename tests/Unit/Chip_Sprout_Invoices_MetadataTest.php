<?php
/**
 * Metadata consistency tests.
 *
 * The supported WordPress and PHP versions are declared in several files that
 * nothing keeps in step: the plugin header, readme.txt (twice), README.md and
 * phpcs.xml. An inconsistent set ships to users — the readme is what WordPress
 * shows on the plugin page, while the header is what the installer checks, and
 * a stale `Tested up to` is a claim nobody verified.
 *
 * The floor was previously guessed rather than derived, so these assertions are
 * deliberately about the declared numbers agreeing with each other and with the
 * tested version, not about the lowest version the code could run on.
 *
 * @package ChipForSproutInvoices
 */

/**
 * Guards the version metadata files.
 */
class Chip_Sprout_Invoices_MetadataTest extends PHPUnit\Framework\TestCase {

	/**
	 * Plugin root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Reads one file from the plugin root.
	 *
	 * @param string $file Relative file name.
	 * @return string
	 */
	private function read( $file ) {
		$path = $this->root . '/' . $file;
		$this->assertFileExists( $path, "{$file} is missing" );
		return (string) file_get_contents( $path );
	}

	/**
	 * Extracts `Requires at least:` from the plugin header.
	 *
	 * @return string
	 */
	private function header_wp_floor() {
		$php = $this->read( 'chip-for-sprout-invoices.php' );
		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Requires at least:\s*([0-9.]+)\s*$/m', $php, $m ),
			'the plugin header has no "Requires at least:" line'
		);
		return $m[1];
	}

	/**
	 * Extracts `Requires at least:` from readme.txt's header block.
	 *
	 * @return string
	 */
	private function readme_wp_floor() {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame(
			1,
			preg_match( '/^Requires at least:\s*([0-9.]+)\s*$/m', $readme, $m ),
			'readme.txt has no "Requires at least:" line'
		);
		return $m[1];
	}

	/**
	 * Every declaration of the WordPress floor must agree.
	 */
	public function test_wordpress_floor_is_consistent() {
		$header = $this->header_wp_floor();
		$readme = $this->readme_wp_floor();

		$this->assertSame(
			$readme,
			$header,
			"the plugin header requires WP {$header} but readme.txt says {$readme}"
		);

		// readme.txt states it a second time, in Minimum Requirements.
		$this->assertStringContainsString(
			"* WordPress {$header} or greater",
			$this->read( 'readme.txt' ),
			"readme.txt's Minimum Requirements does not say WordPress {$header}"
		);

		// README.md is what a GitHub visitor reads before downloading.
		$this->assertStringContainsString(
			"- WordPress {$header} or newer",
			$this->read( 'README.md' ),
			"README.md does not say WordPress {$header}"
		);

		// phpcs.xml decides whether the sniffs demand 6.3-era APIs.
		$this->assertStringContainsString(
			'minimum_supported_wp_version" value="' . $header . '"',
			$this->read( 'phpcs.xml' ),
			"phpcs.xml's minimum_supported_wp_version does not match {$header}"
		);
	}

	/**
	 * The floor must be a version still maintained, not the oldest that works.
	 *
	 * WordPress supports the three most recent branches. 6.3 is the oldest
	 * floor any CHIP WordPress plugin declares; anything below it is EOL.
	 */
	public function test_wordpress_floor_is_not_below_the_house_minimum() {
		$floor = version_compare( $this->header_wp_floor(), '6.3', '>=' );
		$this->assertTrue(
			$floor,
			'the WordPress floor is below 6.3, which every other CHIP WordPress plugin requires '
			. 'and which no longer receives security support'
		);
	}

	/**
	 * `Tested up to` must be at least the floor, and must be a real version.
	 */
	public function test_tested_up_to_is_a_real_version_at_or_above_the_floor() {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame(
			1,
			preg_match( '/^Tested up to:\s*([0-9.]+)\s*$/m', $readme, $m ),
			'readme.txt has no "Tested up to:" line'
		);
		$tested = $m[1];

		$this->assertTrue(
			version_compare( $tested, $this->header_wp_floor(), '>=' ),
			"Tested up to ({$tested}) is below Requires at least (" . $this->header_wp_floor() . ')'
		);

		// The end-to-end suite runs wordpress:7.1, so that is what is proven.
		$this->assertSame(
			'7.1',
			$tested,
			'the end-to-end environment runs WordPress 7.1; Tested up to must name the version '
			. 'actually exercised, not an inherited number'
		);
	}

	/**
	 * The PHP floor is declared in the header and readme.txt, and phpcs agrees.
	 */
	public function test_php_floor_is_consistent() {
		$php = $this->read( 'chip-for-sprout-invoices.php' );
		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Requires PHP:\s*([0-9.]+)\s*$/m', $php, $m ),
			'the plugin header has no "Requires PHP:" line'
		);
		$header = $m[1];

		$this->assertStringContainsString(
			"Requires PHP: {$header}",
			$this->read( 'readme.txt' ),
			"readme.txt's Requires PHP does not match the header ({$header})"
		);
		$this->assertStringContainsString(
			"PHP {$header} or greater",
			$this->read( 'readme.txt' ),
			"readme.txt's Minimum Requirements does not say PHP {$header}"
		);

		// The compat run in CI starts at this version.
		$this->assertStringContainsString(
			'testVersion" value="' . $header . '-',
			$this->read( 'phpcs.xml' ),
			"phpcs.xml's testVersion does not start at the declared PHP floor ({$header})"
		);
	}

	/**
	 * The release zip's folder must carry the slug WordPress expects, and a
	 * version that matches the header — a mismatch makes the release wrong.
	 */
	public function test_version_matches_between_header_readme_and_changelog() {
		$php = $this->read( 'chip-for-sprout-invoices.php' );
		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $php, $m ),
			'the plugin header has no "Version:" line'
		);
		$version = $m[1];

		$this->assertStringContainsString(
			"Stable tag: {$version}",
			$this->read( 'readme.txt' ),
			"readme.txt's Stable tag does not match the header version ({$version})"
		);
		$this->assertStringContainsString(
			"define( 'SA_ADDON_CHIP_VERSION', '{$version}' )",
			$php,
			'the SA_ADDON_CHIP_VERSION constant does not match the header version'
		);
		$this->assertStringContainsString(
			"= {$version} ",
			$this->read( 'changelog.txt' ),
			"changelog.txt has no entry for {$version}"
		);
	}

	/**
	 * setUp.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 2 );
	}
}
