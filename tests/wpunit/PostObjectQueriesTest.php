<?php

class PostObjectQueriesTest extends \Codeception\TestCase\WPTestCase {

	public $current_time;
	public $current_date;
	public $current_date_gmt;
	public $admin;
	public $contributor;

	public function setUp() {
		// before
		parent::setUp();

		$this->current_time     = strtotime( '- 1 day' );
		$this->current_date     = date( 'Y-m-d H:i:s', $this->current_time );
		$this->current_date_gmt = gmdate( 'Y-m-d H:i:s', $this->current_time );
		$this->admin            = $this->factory()->user->create( [
			'role' => 'administrator',
		] );
		$this->contributor = $this->factory()->user->create( [
			'role' => 'contributor',
		] );

		add_shortcode( 'wpgql_test_shortcode', function ( $attrs, $content = null ) {
			global $post;
			if ( 'post' !== $post->post_type ) {
				return $content;
			}

			return 'overridden content';
		} );

		add_shortcode( 'graphql_tests_basic_post_list', function ( $atts ) {
			$query = '
			query basicPostList($first:Int){
				posts(first:$first){
					edges{
						node{
							id
							title
							date
						}
					}
				}
			}
			';

			$variables = [
				'first' => ! empty( $atts['first'] ) ? absint( $atts['first'] ) : 5,
			];

			$data  = do_graphql_request( $query, 'basicPostList', $variables );
			$edges = ! empty( $data['data']['posts']['edges'] ) ? $data['data']['posts']['edges'] : [];

			if ( ! empty( $edges ) && is_array( $edges ) ) {
				$output = '<ul class="gql-test-shortcode-list">';
				foreach ( $edges as $edge ) {
					$node = ! empty( $edge['node'] ) ? $edge['node'] : '';
					if ( ! empty( $node ) && is_array( $node ) ) {
						$output .= '<li id="' . $node['id'] . '">' . $node['title'] . ' ' . $node['date'] . '</li>';
					}
				}
				$output .= '</ul>';
			}

			return ! empty( $output ) ? $output : '';
		} );

	}

	public function tearDown() {
		// your tear down methods here

		// then
		parent::tearDown();
	}

	public function createPostObject( $args ) {

		/**
		 * Set up the $defaults
		 */
		$defaults = [
			'post_author'  => $this->admin,
			'post_content' => 'Test page content',
			'post_excerpt' => 'Test excerpt',
			'post_status'  => 'publish',
			'post_title'   => 'Test Title',
			'post_type'    => 'post',
			'post_date'    => $this->current_date,
		];

		/**
		 * Combine the defaults with the $args that were
		 * passed through
		 */
		$args = array_merge( $defaults, $args );

		/**
		 * Create the page
		 */
		$post_id = $this->factory()->post->create( $args );

		/**
		 * Update the _edit_last and _edit_lock fields to simulate a user editing the page to
		 * test retrieving the fields
		 *
		 * @since 0.0.5
		 */
		update_post_meta( $post_id, '_edit_lock', $this->current_time . ':' . $this->admin );
		update_post_meta( $post_id, '_edit_last', $this->admin );

		/**
		 * Return the $id of the post_object that was created
		 */
		return $post_id;

	}

	/**
	 * testPostQuery
	 *
	 * This tests creating a single post with data and retrieving said post via a GraphQL query
	 *
	 * @since 0.0.5
	 */
	public function testPostQuery() {

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type' => 'post',
		] );

		add_filter('upload_dir', function( $param ) {
			$dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads';
			$param['path'] = $dir;
			return $param;
		});

		/**
		 * Create a featured image and attach it to the post
		 */
		$filename      = ( WPGRAPHQL_PLUGIN_DIR . '/tests/_data/images/test.png' );
		$featured_image_id = $this->factory()->attachment->create_upload_object( $filename );
		update_post_meta( $post_id, '_thumbnail_id', $featured_image_id );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			post(id: \"{$global_id}\") {
				id
				author{
					userId
				}
				commentCount
				commentStatus
				content
				date
				dateGmt
				desiredSlug
				editLast{
					userId
				}
				editLock{
					editTime
					user{
						userId
					}
				}
				enclosure
				excerpt
				status
				link
				menuOrder
				postId
				slug
				toPing
				pinged
				modified
				modifiedGmt
				title
				guid
				featuredImage{
					mediaItemId
					thumbnail: sourceUrl(size: THUMBNAIL)
					medium: sourceUrl(size: MEDIUM)
					full: sourceUrl(size: LARGE)
					sourceUrl
				}
			}
		}";


		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'post' => [
					'id'            => $global_id,
					'author'        => [
						'userId' => $this->admin,
					],
					'commentCount'  => null,
					'commentStatus' => 'open',
					'content'       => apply_filters( 'the_content', 'Test page content' ),
					'date'          => \WPGraphQL\Types::prepare_date_response( null, $this->current_date ),
					'dateGmt'       => \WPGraphQL\Types::prepare_date_response( get_post( $post_id )->post_modified_gmt ),
					'desiredSlug'   => null,
					'editLast'      => [
						'userId' => $this->admin,
					],
					'editLock'      => [
						'editTime' => \WPGraphQL\Types::prepare_date_response( null, $this->current_date ),
						'user'     => [
							'userId' => $this->admin,
						],
					],
					'enclosure'     => null,
					'excerpt'       => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', 'Test excerpt' ) ),
					'status'        => 'publish',
					'link'          => get_permalink( $post_id ),
					'menuOrder'     => null,
					'postId'        => $post_id,
					'slug'          => 'test-title',
					'toPing'        => null,
					'pinged'        => null,
					'modified'      => get_post( $post_id )->post_modified,
					'modifiedGmt'   => \WPGraphQL\Types::prepare_date_response( get_post( $post_id )->post_modified_gmt ),
					'title'         => apply_filters( 'the_title', 'Test Title' ),
					'guid'          => get_post( $post_id )->guid,
					'featuredImage' => [
						'mediaItemId' => $featured_image_id,
						'thumbnail' => wp_get_attachment_image_src( $featured_image_id, 'thumbnail' )[0],
						'medium' => wp_get_attachment_image_src( $featured_image_id, 'medium' )[0],
						'full' => wp_get_attachment_image_src( $featured_image_id, 'full' )[0],
						'sourceUrl' => wp_get_attachment_image_src( $featured_image_id, 'full' )[0]
					],
				],
			],
		];

		wp_delete_attachment( $featured_image_id, true );

		$this->assertEquals( $expected, $actual );

	}

	/**
	 * testPostQueryWherePostDoesNotExist
	 *
	 * Tests a query for non existant post.
	 *
	 * @since 0.0.34
	 */
	public function testPostQueryWherePostDoesNotExist() {
		/**
		 * Create the global ID based on the plugin_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', 'doesNotExist' );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
 		query {
			post(id: \"{$global_id}\") {
				slug
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * There should be an internal server error when requesting a non-existent post
		 */
		$this->assertArrayHasKey( 'errors', $actual );
	}

	/**
	 * testPostQueryWithoutFeaturedImage
	 *
	 * This tests querying featuredImage on a post wihtout one.
	 *
	 * @since 0.0.34
	 */
	public function testPostQueryWithoutFeaturedImage() {
		/**
		 * Create Post
		 */
		$post_id = $this->createPostObject( [
			'post_type' => 'post'
		] );
		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );
		$query     = "
		query {
			post(id: \"{$global_id}\") {
				id
				featuredImage {
					altText
					author {
						id
					}
					caption
					commentCount
					commentStatus
					comments {
						edges {
							node {
								id
							}
						}
					}
					content
					date
					dateGmt
					description
					desiredSlug
					editLast {
						userId
					}
					editLock {
						editTime
					}
					enclosure
					excerpt
					guid
					id
					link
					mediaDetails {
						file
						height
						meta {
							aperture
							credit
							camera
							caption
							createdTimestamp
							copyright
							focalLength
							iso
							shutterSpeed
							title
							orientation
							keywords
						}
						sizes {
							name
							file
							width
							height
							mimeType
							sourceUrl
						}
						width
					}
					mediaItemId
					mediaType
					menuOrder
					mimeType
					modified
					modifiedGmt
					parent {
						...on Post {
							id
						}
					}
					pingStatus
					slug
					sourceUrl
					status
					title
					toPing
				}
			}
		}
    ";

		$actual   = do_graphql_request( $query );
		$expected = [
			"data" => [
				"post" => [
					"id"            => $global_id,
					"featuredImage" => null
				]
			]
		];
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * testPostQueryWithComments
	 *
	 * This tests creating a single post with comments.
	 *
	 * @since 0.0.5
	 */
	public function testPostQueryWithComments() {

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type' => 'post',
			'post_status' => 'publish'
		] );

		// Create a comment and assign it to post.
		$comment_id = $this->factory()->comment->create( [
			'comment_post_ID' => $post_id,
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			post(id: \"{$global_id}\") {
				id
				commentCount
				commentStatus
				comments {
					edges {
						node {
							commentId
						}
					}
				}
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'post' => [
					'id'            => $global_id,
					'comments'      => [
						'edges' => [
							[
								'node' => [
									'commentId' => $comment_id,
								],
							],
						],
					],
					'commentCount'  => 1,
					'commentStatus' => 'open',
				],
			],
		];

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * testPageQueryWithParent
	 *
	 * This tests a hierarchical post type assigned a parent.
	 *
	 * @since 0.0.5
	 */
	public function testPageQueryWithParent() {

		// Parent post.
		$parent_id = $this->createPostObject( [
			'post_type' => 'page',
		] );

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type'   => 'page',
			'post_parent' => $parent_id,
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'page', $post_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			page(id: \"{$global_id}\") {
				id
				parent {
					... on Page {
						pageId
					}
				}
				ancestors {
					... on Page {
						pageId
					}
				}
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'page' => [
					'id'        => $global_id,
					'parent'    => [
						'pageId' => $parent_id,
					],
					'ancestors' => [
						[
							'pageId' => $parent_id,
						],
					],
				],
			],
		];

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * testPostQueryWithTags
	 *
	 * This tests creating a single post with assigned post tags.
	 *
	 * @since 0.0.5
	 */
	public function testPostQueryWithTags() {

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type' => 'post',
		] );

		// Create a comment and assign it to post.
		$tag_id = $this->factory()->tag->create( [
			'name' => 'Test Tag',
		] );

		wp_delete_object_term_relationships( $post_id, [ 'post_tag', 'category' ] );
		wp_set_object_terms( $post_id, $tag_id, 'post_tag', true );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			post(id: \"{$global_id}\") {
				id
				tags {
					edges {
						node {
							tagId
							name
						}
					}
				}
				tagNames:termNames(taxonomies:[TAG])
				terms{
				  ...on Tag{
				    name
				  }
				}
				termNames
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'post' => [
					'id'        => $global_id,
					'tags'      => [
						'edges' => [
							[
								'node' => [
									'tagId' => $tag_id,
									'name'  => 'Test Tag',
								],
							],
						],
					],
					'tagNames'  => [ 'Test Tag' ],
					'terms'     => [
						[
							'name' => 'Test Tag',
						],
					],
					'termNames' => [ 'Test Tag' ],
				],
			],
		];

		$this->assertEquals( $expected, $actual );
	}

	public function testPostQueryWithTermFields() {

		$post_title = uniqid();
		$cat_name   = uniqid();
		$tag_name   = uniqid();

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type'  => 'post',
			'post_title' => $post_title
		] );

		// Create a comment and assign it to post.
		$category_id = $this->factory()->category->create( [
			'name' => $cat_name,
			'slug' => $cat_name,
		] );

		$tag_id = $this->factory()->tag->create( [
			'name' => $tag_name,
			'slug' => $tag_name,
		] );

		wp_set_object_terms( $post_id, $category_id, 'category' );
		wp_set_object_terms( $post_id, $tag_id, 'post_tag' );

		$query = '
		query getPostWithTermFields( $id:ID! ){
			post( id:$id ) {
			  postId
			  title
			  tagSlugs:termSlugs(taxonomies:[TAG])
			  catSlugs:termSlugs(taxonomies:[CATEGORY])
			  termSlugs
			  catNames:termNames(taxonomies:[CATEGORY])
			  tagNames:termNames(taxonomies:[TAG])
			  termNames
			  tags:terms(taxonomies:[TAG]) {
			     ... on Tag {
			       slug
			       name
			     }
			  }
			  cats:terms(taxonomies:[CATEGORY]) {
			    ... on Category {
			       slug
			       name
			     }
			  }
			  allTerms:terms(taxonomies:[TAG,CATEGORY]) {
			     ... on Tag {
			       slug
			       name
			     }
			     ... on Category {
			       slug
			       name
			     }
			  }
			}
		}
		';

		$variables = [
			'id' => \GraphQLRelay\Relay::toGlobalId( 'post', $post_id ),
		];

		$actual = do_graphql_request( $query, 'getPostWithTermFields', $variables );

		$this->assertArrayNotHasKey( 'errors', $actual );

		$post = $actual['data']['post'];

		$this->assertEquals( $post_id, $post['postId'] );
		$this->assertEquals( $post_title, $post['title'] );

		// Slug fields
		$this->assertTrue( in_array( $tag_name, $post['tagSlugs'], true ) );
		$this->assertTrue( in_array( $cat_name, $post['catSlugs'], true ) );
		$this->assertTrue( in_array( $tag_name, $post['termSlugs'], true ) );
		$this->assertTrue( in_array( $cat_name, $post['termSlugs'], true ) );

		// Name fields
		$this->assertTrue( in_array( $tag_name, $post['tagNames'], true ) );
		$this->assertTrue( in_array( $cat_name, $post['catNames'], true ) );
		$this->assertTrue( in_array( $tag_name, $post['termNames'], true ) );
		$this->assertTrue( in_array( $cat_name, $post['termNames'], true ) );

		// tag and cat fields
		$tag_names      = wp_list_pluck( $post['tags'], 'name' );
		$cat_names      = wp_list_pluck( $post['cats'], 'name' );
		$all_term_names = wp_list_pluck( $post['allTerms'], 'name' );

		$this->assertTrue( in_array( $tag_name, $tag_names, true ) );
		$this->assertTrue( in_array( $cat_name, $cat_names, true ) );
		$this->assertTrue( in_array( $tag_name, $all_term_names, true ) );
		$this->assertTrue( in_array( $cat_name, $all_term_names, true ) );

	}

	/**
	 * testPostQueryWithCategories
	 *
	 * This tests creating a single post with categories assigned.
	 *
	 * @since 0.0.5
	 */
	public function testPostQueryWithCategories() {

		wp_set_current_user( $this->admin );

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_author' => $this->admin,
			'post_title' => 'test post',
		] );

		// Create a comment and assign it to post.
		$category_id = $this->factory()->category->create( [
			'name' => 'A category',
		] );

		wp_set_object_terms( $post_id, $category_id, 'category', false );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			post(id: \"{$global_id}\") {
				id
				categories {
					edges {
						node {
							categoryId
							name
						}
					}
				}
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );


		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'post' => [
					'id'         => $global_id,
					'categories' => [
						'edges' => [
							[
								'node' => [
									'categoryId' => $category_id,
									'name'       => 'A category',
								],
							],
						],
					],
				],
			],
		];



		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Test querying a post using the postBy query
	 */
	public function testPostByIdQuery() {

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type'  => 'post',
			'post_title' => 'This is a title, yo',
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			postBy(id: \"{$global_id}\") {
				id
				title
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'postBy' => [
					'id'    => $global_id,
					'title' => 'This is a title, yo',
				],
			],
		];

		$this->assertEquals( $expected, $actual );

	}

	/**
	 * Test querying a post using the postBy query and the URI arg
	 */
	public function testPostByUriQuery() {

		/**
		 * Create a post
		 */
		$post_id = $this->createPostObject( [
			'post_type'  => 'post',
			'post_title' => 'This is a title, yo',
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		$slug = get_post( $post_id )->post_name;

		/**
		 * Create the GraphQL query.
		 */
		$query = "
		query {
			postBy(slug: \"{$slug}\") {
				id
				title
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'postBy' => [
					'id'    => $global_id,
					'title' => 'This is a title, yo',
				],
			],
		];

		$this->assertEquals( $expected, $actual );

	}

	/**
	 * Test querying a page using the pageBy query and the URI arg
	 */
	public function testPageByUri() {

		/**
		 * Create a page
		 */
		$parent_id = $this->createPostObject( [
			'post_type'  => 'page',
			'post_title' => 'Parent Page',
			'post_name'  => 'parent-page',
		] );

		$child_id = $this->createPostObject( [
			'post_type'   => 'page',
			'post_title'  => 'Child Page',
			'post_name'   => 'child-page',
			'post_parent' => $parent_id,
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_child_id = \GraphQLRelay\Relay::toGlobalId( 'page', $child_id );

		/**
		 * Get the uri to the Child Page
		 */
		$uri = get_page_uri( $child_id );

		/**
		 * Create the query string to pass to the $query
		 */
		$query = "
		query {
			pageBy(uri: \"{$uri}\") {
				id
				title
				uri
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * Establish the expectation for the output of the query
		 */
		$expected = [
			'data' => [
				'pageBy' => [
					'id'    => $global_child_id,
					'title' => 'Child Page',
					'uri'   => $uri
				],
			],
		];

		$this->assertEquals( $expected, $actual );

	}

	/**
	 * Test querying the same node multiple ways and ensuring we get the same response each time
	 */
	public function testPageByQueries() {

		wp_set_current_user( $this->admin );

		$post_id = $this->createPostObject( [
			'post_type'  => 'page',
			'post_title' => 'Page Dawg',
			'post_author' => $this->admin,
			'post_status' => 'publish'
		] );

		$path      = get_page_uri( $post_id );
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'page', $post_id );

		/**
		 * Let's query the same node 3 different ways, then assert it's the same node
		 * each time
		 */
		$query = '
		{
		  pages(first:1){
		    edges{
		      node{
		        ...pageData
		      }
		    }
		  }
		  byUri:pageBy(uri:"' . $path . '") {
		    ...pageData
		  }
		  byPageId:pageBy(pageId:' . $post_id . '){
		    ...pageData
		  }
		  byId:pageBy(id:"' . $global_id . '"){
		    ...pageData
		  }
		}

		fragment pageData on Page {
		  __typename
		  id
		  pageId
		  title
		  uri
		  link
		  slug
		  date
		}
		';

		$actual = do_graphql_request( $query );

		$this->assertArrayNotHasKey( 'errors', $actual );

		$node     = $actual['data']['pages']['edges'][0]['node'];
		$byUri    = $actual['data']['byUri'];
		$byPageId = $actual['data']['byPageId'];
		$byId     = $actual['data']['byId'];

		$this->assertNotEmpty( $node );
		$this->assertEquals( 'Page', $actual['data']['pages']['edges'][0]['node']['__typename'] );
		$this->assertEquals( $node, $byUri );
		$this->assertEquals( $node, $byPageId );
		$this->assertEquals( $node, $byId );

	}

	/**
	 * Query with an invalid ID, should return an error
	 */
	public function testPostByQueryWithInvalidId() {

		$query = '{
			postBy(id: "invalid ID") {
				id
				title
			}
		}';

		$actual = do_graphql_request( $query );

		/**
		 * This should return an error as we tried to query with an invalid ID
		 */
		$this->assertArrayHasKey( 'errors', $actual );

	}

	/**
	 * Query for a post that was deleted
	 */
	public function testPostByQueryAfterPostWasDeleted() {

		/**
		 * Create the post
		 */
		$post_id = $this->createPostObject( [
			'post_type'  => 'post',
			'post_title' => 'Post that will be deleted',
		] );

		/**
		 * Get the ID
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Delete the post, because we want to query for a post that's been deleted
		 * and make sure it returns an error properly.
		 */
		wp_delete_post( $post_id, true );

		/**
		 * Query for the post
		 */
		$query = '{
			postBy(id: "' . $global_id . '") {
				id
				title
			}
		}';

		/**
		 * Run the query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * This should return an error as we tried to query for a deleted post
		 */
		$this->assertArrayHasKey( 'errors', $actual );

	}

	/**
	 * Test querying for a post with an ID that belongs to a different type
	 */
	public function testPostByQueryWithIDForADifferentType() {

		/**
		 * Create the page
		 */
		$page_id = $this->createPostObject( [
			'post_type'  => 'page',
			'post_title' => 'A Test Page',
		] );

		/**
		 * Get the ID
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'page', $page_id );

		/**
		 * Query for the post, using a global ID for a page
		 */
		$query = '{
			postBy(id: "' . $global_id . '") {
				id
				title
			}
		}';

		/**
		 * Run the query
		 */
		$actual = do_graphql_request( $query );

		/**
		 * This should return an error as we tried to query for a post using a Page ID
		 */
		$this->assertArrayHasKey( 'errors', $actual );

	}

	/**
	 * testPostObjectFieldRawFormat
	 *
	 * This tests that we request the "raw" format from post object fields.
	 *
	 * @since 0.0.18
	 */
	public function testPostObjectFieldRawFormat() {
		/**
		 * Create a post that we can query via GraphQL.
		 */
		$graphql_query_post_id = $this->createPostObject( array() );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $graphql_query_post_id );

		/**
		 * Add a filter that should be called when the content fields are
		 * requested in "rendered" format (the default).
		 */
		function override_for_testPostObjectFieldRawFormat() {
			return 'Overridden for testPostObjectFieldRawFormat';
		}

		add_filter( 'the_content', 'override_for_testPostObjectFieldRawFormat', 10, 0 );
		add_filter( 'the_excerpt', 'override_for_testPostObjectFieldRawFormat', 10, 0 );
		add_filter( 'the_title', 'override_for_testPostObjectFieldRawFormat', 10, 0 );

		$graphql_query = "
		query {
			post(id: \"{$global_id}\") {
				id
				content
				excerpt
				title
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$graphql_query_data = do_graphql_request( $graphql_query );

		/**
		 * Assert that the filters were called.
		 */
		$this->assertEquals( 'Overridden for testPostObjectFieldRawFormat', $graphql_query_data['data']['post']['content'] );
		$this->assertEquals( 'Overridden for testPostObjectFieldRawFormat', $graphql_query_data['data']['post']['excerpt'] );
		$this->assertEquals( 'Overridden for testPostObjectFieldRawFormat', $graphql_query_data['data']['post']['title'] );

		/**
		 * Run the same query but request the fields in raw form.
		 */
		wp_set_current_user( $this->admin );
		$graphql_query = "
		query {
			post(id: \"{$global_id}\") {
				id
				content(format: RAW)
				excerpt(format: RAW)
				title(format: RAW)
			}
		}";

		/**
		 * Rerun the GraphQL query
		 */
		$graphql_query_data = do_graphql_request( $graphql_query );

		/**
		 * Assert that the filters were not called.
		 */
		$this->assertEquals( 'Test page content', $graphql_query_data['data']['post']['content'] );
		$this->assertEquals( 'Test excerpt', $graphql_query_data['data']['post']['excerpt'] );
		$this->assertEquals( 'Test Title', $graphql_query_data['data']['post']['title'] );
	}

	/**
	 * testPostQueryPostDataSetup
	 *
	 * This tests that we correctly setup post data for field resolvers.
	 *
	 * @since 0.0.18
	 */
	public function testPostQueryPostDataSetup() {
		/**
		 * Create a post that we can query via GraphQL.
		 */
		$graphql_query_post_id = $this->factory()->post->create();

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $graphql_query_post_id );

		$graphql_query = "
		query {
			post(id: \"{$global_id}\") {
				id
				content
			}
		}";

		/**
		 * Add a filter that will be called when the content field from the query
		 * above is resolved.
		 */
		add_filter( 'the_content', function () use ( $graphql_query_post_id ) {
			/**
			 * Assert that post data was correctly set up.
			 */
			$this->assertEquals( $graphql_query_post_id, $GLOBALS['post']->ID );

			return 'Overridden for testPostQueryPostDataSetup';
		}, 99, 0 );

		/**
		 * Run the GraphQL query
		 */
		$graphql_query_data = do_graphql_request( $graphql_query );

		/**
		 * Assert that the filter was called.
		 */
		$this->assertEquals( 'Overridden for testPostQueryPostDataSetup', $graphql_query_data['data']['post']['content'] );
	}

	/**
	 * testPostQueryPostDataReset
	 *
	 * This tests that we correctly reset postdata without disturbing anything
	 * external to WPGraphQL.
	 *
	 * @since 0.0.18
	 */
	public function testPostQueryPostDataReset() {
		/**
		 * Create a post and simulate being in the main query / loop. We want to
		 * make sure that the query is properly reset after the GraphQL request is
		 * completed.
		 */
		global $post;
		$main_query_post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $main_query_post_id ) );
		setup_postdata( $post );

		/**
		 * Create another post that we can query via GraphQL.
		 */
		$graphql_query_post_id = $this->factory()->post->create();

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $graphql_query_post_id );

		/**
		 * Create the GraphQL query.
		 */
		$graphql_query = "
		query {
			post(id: \"{$global_id}\") {
				id
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		do_graphql_request( $graphql_query );

		/**
		 * Assert that the query has been reset to the main query.
		 */
		$this->assertEquals( $main_query_post_id, $post->ID );

		// setup_postdata sets the global $id too so assert it is reset back to
		// original
		// https://github.com/WordPress/WordPress/blob/b5542c6b1b41d69b4e5c26ef8280c6e85de67224/wp-includes/class-wp-query.php#L4158
		$this->assertEquals( $main_query_post_id, $GLOBALS['id'] );

	}

	/**
	 *
	 */
	public function testPostQueryWithShortcodeInContent() {

		/**
		 * Create a post and simulate being in the main query / loop. We want to
		 * make sure that the query is properly reset after the GraphQL request is
		 * completed.
		 */
		global $post;
		$main_query_post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $main_query_post_id ) );
		setup_postdata( $post );

		/**
		 * Create another post that we can query via GraphQL.
		 */
		$graphql_query_post_id = $this->factory()->post->create( [
			'post_content' => '<p>Some content before the shortcode</p>[wpgql_test_shortcode]some test content[/wpgql_test_shortcode]<p>Some content after the shortcode</p>'
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $graphql_query_post_id );

		/**
		 * Create the GraphQL query.
		 */
		$graphql_query = "
		query {
			post(id: \"{$global_id}\") {
				id
				content
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$response = do_graphql_request( $graphql_query );

		$this->assertNotFalse( strpos( $response['data']['post']['content'], 'Some content before the shortcode' ) );
		$this->assertNotFalse( strpos( $response['data']['post']['content'], 'overridden content' ) );
		$this->assertNotFalse( strpos( $response['data']['post']['content'], 'Some content after the shortcode' ) );

		/**
		 * Asset that the query has been reset to the main query.
		 */
		$this->assertEquals( $main_query_post_id, $post->ID );

	}

	public function testPostQueryPageWithShortcodeInContent() {

		/**
		 * Create a post and simulate being in the main query / loop. We want to
		 * make sure that the query is properly reset after the GraphQL request is
		 * completed.
		 */
		global $post;
		$main_query_post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $main_query_post_id ) );
		setup_postdata( $post );

		/**
		 * Create another post that we can query via GraphQL.
		 */
		$graphql_query_page_id = $this->factory()->post->create( [
			'post_content' => '<p>Some content before the shortcode</p>[wpgql_test_shortcode]some test content[/wpgql_test_shortcode]<p>Some content after the shortcode</p>',
			'post_type'    => 'page',
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'page', $graphql_query_page_id );

		/**
		 * Create the GraphQL query.
		 */
		$graphql_query = "
		query {
			page(id: \"{$global_id}\") {
				id
				content
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$response = do_graphql_request( $graphql_query );

		$this->assertNotFalse( strpos( $response['data']['page']['content'], 'Some content before the shortcode' ) );
		$this->assertNotFalse( strpos( $response['data']['page']['content'], 'some test content' ) );
		$this->assertNotFalse( strpos( $response['data']['page']['content'], 'Some content after the shortcode' ) );

		/**
		 * Asset that the query has been reset to the main query.
		 */
		$this->assertEquals( $main_query_post_id, $post->ID );

	}

	/**
	 * This was a use case presented as something that _could_ break things.
	 *
	 * A WPGraphQL Query could be used within a shortcode to populate the shortcode content. If the
	 * global $post was set and _not_ reset, the content after the query would be broken.
	 *
	 * This simply ensures that the content before and after the shortcode are working as expected
	 * and that the global
	 * $post is reset properly after a gql query is performed.
	 */
	public function testGraphQLQueryShortcodeInContent() {

		/**
		 * Create a post and simulate being in the main query / loop. We want to
		 * make sure that the query is properly reset after the GraphQL request is
		 * completed.
		 */
		global $post;
		$main_query_post_id = $this->factory()->post->create();
		$this->go_to( get_permalink( $main_query_post_id ) );
		setup_postdata( $post );

		/**
		 * Create another post that we can query via GraphQL.
		 */
		$graphql_query_page_id = $this->factory()->post->create( [
			'post_content' => '<p>Some content before the shortcode</p>[graphql_tests_basic_post_list]<p>Some content after the shortcode</p>',
			'post_type'    => 'page',
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'page', $graphql_query_page_id );

		/**
		 * Create the GraphQL query.
		 */
		$graphql_query = "
		query {
			page(id: \"{$global_id}\") {
				id
				content
			}
		}";

		/**
		 * Run the GraphQL query
		 */
		$response = do_graphql_request( $graphql_query );

		/**
		 * Here we're asserting that the shortcode is showing up (rendered) in the middle of the content, but that the content before and after
		 * is in place as expected as well.
		 */
		$this->assertNotFalse( strpos( $response['data']['page']['content'], 'Some content before the shortcode' ) );
		$this->assertNotFalse( strpos( $response['data']['page']['content'], '<ul class="gql-test-shortcode-list">' ) );
		$this->assertNotFalse( strpos( $response['data']['page']['content'], 'Some content after the shortcode' ) );

		/**
		 * Asset that the query has been reset to the main query.
		 */
		$this->assertEquals( $main_query_post_id, $post->ID );

	}

	/**
	 * Assert that no data is being leaked on private posts that are directly queried without auth.
	 */
	public function testPrivatePosts() {

		$post_id = $this->factory()->post->create( [
			'post_status' => 'private',
			'post_content' => 'Test',
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		$query = "
		query {
			postBy(id: \"{$global_id}\") {
			    status
			    title
			    categories {
			      nodes {
			        id
			        name
			        slug
			      }
			    }
		    }
	    }";

		$expected = [
			'data' => [
				'postBy' => [
					'status' => null,
					'title' => null,
					'categories' => [
						'nodes' => [],
					],
				]
			]
		];

		$actual = do_graphql_request( $query );
		$this->assertEquals( $expected, $actual );

	}

	/**
	 * Test restricted posts returned on certain statuses
	 * @dataProvider dataProviderRestrictedPosts
	 */
	public function testRestrictedPosts( $status, $author, $user, $restricted ) {

		if ( ! empty( $author ) ) {
			$author = $this->{$author};
		}
		if ( ! empty( $user ) ) {
			$user = $this->{$user};
		}

		$title = 'Content from author: ' . (string)$author;
		$content = 'Test Content';
		$post_date = time();

		if ( 'future' === $status ) {
			$post_date = $post_date + 600;
		}

		$post_date = date( 'Y-m-d H:i:s', $post_date );
		$post_id = $this->factory()->post->create( [
			'post_status' => $status,
			'post_author' => $author,
			'post_title' => $title,
			'post_content' => $content,
			'post_date' => $post_date,
		] );

		/**
		 * Create the global ID based on the post_type and the created $id
		 */
		$global_id = \GraphQLRelay\Relay::toGlobalId( 'post', $post_id );

		/**
		 * Create the GraphQL query.
		 */
		$graphql_query = "
		query {
			post(id: \"{$global_id}\") {
				id
				title
				status
				author{
					userId
				}
				content
			}
		}";

		wp_set_current_user( $user );

		$actual = do_graphql_request( $graphql_query );

		$expected = [
			'data' => [
				'post' => [
					'id' => $global_id,
					'title' => $title,
					'status' => $status,
					'author' => [
						'userId' => $author
					],
					'content' => apply_filters( 'the_content', $content ),
				]
			]
		];

		if ( true === $restricted ) {
			$expected['data']['post']['content'] = null;
			$expected['data']['post']['author'] = null;
		}

		if ( 0 === $author ) {
			$expected['data']['post']['author'] = null;
		}

		/**
		 * If the status is not "publish" and the user is a subscriber, the Post is considered
		 * private, so trying to fetch a private post by ID will return an error
		 */
		if ( 'publish' !== $status && ! current_user_can( get_post_type_object( get_post( $post_id )->post_type )->cap->edit_posts ) ) {
			$this->assertArrayHasKey( 'errors', $actual );
		} else {
			$this->assertEquals( $expected, $actual );
		}


	}

	public function dataProviderRestrictedPosts() {

		$test_vars = [];
		$statuses = [ 'future', 'draft', 'pending' ];

		foreach ( $statuses as $status ) {
			$test_vars[] = [
				'status' => $status,
				'author' => 0,
				'user' => 'admin',
				'restricted' => false,
			];

			$test_vars[] = [
				'status' => $status,
				'author' => 0,
				'user' => 0,
				'restricted' => true,
			];

			$test_vars[] = [
				'status' => $status,
				'author' => 'contributor',
				'user' => 'contributor',
				'restricted' => false,
			];

		}

		return $test_vars;

	}

	/**
	 * Test the scenario where a post is assigned to an author
	 * who is not a user on the site. This could happen for instance,
	 * if the user was deleted, but their posts were never trashed
	 * or assigned to another user.
	 */
	public function testQueryPostsWithOrphanedAuthorDoesntThrowErrors() {
		global $wpdb;

		$highest_user_id     = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID DESC limit 0,1" );
		$nonexistent_user_id = $highest_user_id + 1;

		// Create a new post assigned to a nonexistent user ID.
		$post_id = wp_insert_post( [
			'post_title'   => 'Post assigned to a non-existent user',
			'post_content' => 'Post assigned to a non-existent user',
			'post_status'  => 'publish',
			'post_author'  => $nonexistent_user_id,
		] );

		$query = "
		{
			posts(first: 5) {
				nodes {
					postId
					author {
						userId
						name
					}
				}
			}
		}
		";

		$actual = graphql( [ 'query' => $query ] );

		$this->assertTrue( $post_id && ! is_wp_error( $post_id ) );
		$this->assertArrayNotHasKey( 'errors', $actual );

		// Verify that the ID of the first post matches the one we just created.
		$this->assertEquals( $post_id, $actual['data']['posts']['nodes'][0]['postId'] );

		// Verify that the 'author' field is set to null, since the user ID is invalid.
		$this->assertEquals( null, $actual['data']['posts']['nodes'][0]['author'] );

		wp_delete_post( $post_id, true );

	}

	/**
	 * Tests to make sure the page set as the front page shows as the front page
	 *
	 * @throws Exception
	 */
	public function testIsFrontPage() {

		/**
		 * Make sure no page is set as the front page
		 */
		update_option( 'show_on_front', 'post' );
		update_option( 'page_on_front', 0 );

		$pageId = $this->factory()->post->create([
			'post_status' => 'publish',
			'post_type' => 'page',
			'post_title' => 'Test Front Page'
		]);

		$other_pageId = $this->factory()->post->create([
			'post_status' => 'publish',
			'post_type' => 'page',
			'post_title' => 'Test Not Front Page'
		]);

		$query = '
		query Page( $pageId: Int ) {
		  pageBy( pageId: $pageId ) {
		    id
		    title
		    isFrontPage
		  }
 		}
		';

		$actual = graphql([
			'query' => $query,
			'variables' => [
				'pageId' => $pageId,
			],
		]);

		codecept_debug( $actual );

		$this->assertArrayNotHasKey( 'errors', $actual );
		$this->assertFalse( $actual['data']['pageBy']['isFrontPage'] );

		/**
		 * Set the page as the front page
		 */
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $pageId );

		/**
		 * Query again
		 */
		$actual = graphql([
			'query' => $query,
			'variables' => [
				'pageId' => $pageId,
			],
		]);

		codecept_debug( $actual );

		/**
		 * Assert that the page is showing as the front page
		 */
		$this->assertArrayNotHasKey( 'errors', $actual );
		$this->assertTrue( $actual['data']['pageBy']['isFrontPage'] );

		/**
		 * Query a page that is NOT set as the front page
		 * so we can assert that isFrontPage is FALSE for it
		 */
		$actual = graphql([
			'query' => $query,
			'variables' => [
				'pageId' => $other_pageId, // <-- NOTE OTHER PAGE ID
			],
		]);

		$this->assertArrayNotHasKey( 'errors', $actual );
		$this->assertFalse( $actual['data']['pageBy']['isFrontPage'] );


	}

}
