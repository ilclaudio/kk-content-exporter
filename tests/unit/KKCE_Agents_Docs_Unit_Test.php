<?php
/**
 * Contract test: the AI context documents exist and their internal references are valid.
 *
 * @package KKCE
 */

/**
 * Consistency of the AGENTS documentation.
 */
class KKCE_Agents_Docs_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Local files ignored by Git that may be missing on a clone.
	 *
	 * @var string[]
	 */
	const LOCAL_ONLY_FILES = array(
		'AGENTS/ISSUES_TODO.md',
		'AGENTS/ISSUES_RESOLVED.md',
		'AGENTS/CODE_REVIEW_PROGRESS.md',
	);

	/**
	 * Versioned documents of the AI context.
	 *
	 * @var string[]
	 */
	const DOCUMENTS = array(
		'AGENTS.md',
		'CLAUDE.md',
		'AGENTS/AI_BOOTSTRAP.md',
		'AGENTS/PROJECT.md',
		'AGENTS/ARCHITECTURE.md',
		'AGENTS/AI_BEHAVIOR.md',
		'AGENTS/CODING_STANDARDS.md',
		'AGENTS/GIT_WORKFLOW.md',
		'AGENTS/CODE_REVIEW.md',
		'AGENTS/I18N.md',
	);

	/**
	 * The entry points and the AGENTS documents exist.
	 */
	public function test_documents_exist() {
		foreach ( self::DOCUMENTS as $file ) {
			$this->assertFileExists( KKCE_TEST_PLUGIN_DIR . '/' . $file, "{$file} is missing" );
		}
	}

	/**
	 * Every repository file cited between backticks in the AI context exists, except local-only files.
	 *
	 * Paths are written relative to the plugin root (`AGENTS/PROJECT.md`, `tests/README.md`);
	 * `../` prefixes used inside AGENTS/ are resolved to the root.
	 */
	public function test_cited_repository_files_exist() {
		foreach ( self::DOCUMENTS as $document ) {
			preg_match_all( '/`((?:\.\.\/)?(?:AGENTS|tests|includes|bin|languages|\.githooks)\/[A-Za-z0-9_\/.-]+\.(?:md|php|sh))`|`((?:\.\.\/)?(?:AGENTS|CLAUDE|README|CHANGELOG|CONTRIBUTING|SECURITY)\.md)`/', $this->read_plugin_file( $document ), $matches );
			$cited = array_filter( array_merge( $matches[1], $matches[2] ) );
			foreach ( array_unique( $cited ) as $file ) {
				$file = preg_replace( '/^\.\.\//', '', $file );
				if ( in_array( $file, self::LOCAL_ONLY_FILES, true ) || str_contains( $file, '<' ) ) {
					continue;
				}
				$this->assertFileExists( KKCE_TEST_PLUGIN_DIR . '/' . $file, "{$file} is cited in {$document} but does not exist" );
			}
		}
	}

	/**
	 * Every `composer run <script>` command cited in the AI context and in the developer documents is a
	 * script defined in composer.json.
	 */
	public function test_cited_composer_scripts_exist() {
		$composer = json_decode( $this->read_plugin_file( 'composer.json' ), true );
		$scripts  = array_keys( $composer['scripts'] );
		$cited_in = array_merge( self::DOCUMENTS, array( 'README.md', 'CONTRIBUTING.md', 'tests/README.md', 'tests/unit/README.md', 'tests/integration/README.md' ) );
		foreach ( $cited_in as $document ) {
			preg_match_all( '/composer run ([a-z][a-z0-9:-]*)/', $this->read_plugin_file( $document ), $matches );
			foreach ( array_unique( $matches[1] ) as $script ) {
				$this->assertContains( $script, $scripts, "composer run {$script} is cited in {$document} but is not defined in composer.json" );
			}
		}
	}

	/**
	 * Every AGENTS/*.md document is listed in the File Map of AI_BOOTSTRAP.md.
	 */
	public function test_every_agents_document_is_in_the_file_map() {
		$bootstrap = $this->read_plugin_file( 'AGENTS/AI_BOOTSTRAP.md' );
		foreach ( glob( KKCE_TEST_PLUGIN_DIR . '/AGENTS/*.md' ) as $path ) {
			$name = basename( $path );
			if ( in_array( 'AGENTS/' . $name, self::LOCAL_ONLY_FILES, true ) ) {
				continue;
			}
			$this->assertStringContainsString( "`{$name}`", $bootstrap, "AGENTS/{$name} is not in the File Map of AI_BOOTSTRAP.md" );
		}
	}
}
