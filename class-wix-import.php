<?php

use Willtj\PhpDraftjsHtml\Converter;
use Prezly\DraftPhp\Converter as DraftConverter;

class WixBlogImport
{

    /**
     * Wix Base API URL
     * 
     * @var string
     */
    public $api_url = 'https://www.wixapis.com/blog/v3';

    /**
     * Wix Static Media URL
     * 
     * @var string
     */
    public $static_media_url = 'https://static.wixstatic.com/media';

    /**
     * Wix API Key
     * 
     * @var string
     */
    public $api_key;

    /**
     * Constructor
     *
     * @param string $api_key Wix API Key
     * 
     */
    public function __construct($api_key)
    {
        $this->api_key = $api_key;
    }

    /**
     * Get Wix Blog Posts
     * 
     * @return array
     */
    public function get_posts($params = [])
    {
        // Set default params
        $params = wp_parse_args($params, [
            'fieldsToInclude' => 'CONTENT',
            'paging' => [
                'limit' => 100,
                'offset' => 0,
            ]
        ]);

        // Build Request URL
        $request_url = add_query_arg($params, $this->api_url . '/posts');

        // Create Request
        $response = wp_remote_get($request_url, [
            'headers' => [
                'Authorization' => $this->api_key,
            ],
        ]);

        // Check for error
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('wix_blog_import_api_error', esc_html__('Error fetching Wix Blog', 'WixBlogImport'));
        }

        // Get response and decode 
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body;
    }

    /**
     * Get Categories
     * 
     * @return array
     */
    public function get_categories()
    {
        // Build Request URL
        $request_url = $this->api_url . '/categories';

        // Create Request
        $response = wp_remote_get($request_url, [
            'headers' => [
                'Authorization' => $this->api_key,
            ],
        ]);

        // Check for error
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('wix_blog_import_api_error', esc_html__('Error fetching Wix Blog', 'WixBlogImport'));
        }

        // Get response and decode
        $body = json_decode(wp_remote_retrieve_body($response));

        return $body;
    }

    /**
     * Get Tag by ID
     * 
     * @param int $id Tag ID
     * 
     * @return array
     */
    public function get_tag($tag_id)
    {
        // Build Request URL
        $request_url = $this->api_url . '/tags/' . $tag_id;

        // Create Request
        $response = wp_remote_get($request_url, [
            'headers' => [
                'Authorization' => $this->api_key,
            ],
        ]);

        // Check for error
        if (is_wp_error($response)) {
            return $response;
        }

        // Get response and decode
        $body = json_decode(wp_remote_retrieve_body($response));

        return $body;
    }

    /**
     * Import Method
     * 
     * @return void
     */
    public function import($params = [])
    {
        // Get categories
        $categories = $this->get_categories();

        // check for error
        if (!is_wp_error($categories)) {
            foreach ($categories->categories as $category) {

                // Create category
                $category_id = wp_insert_term($category->label, 'category', ['slug' => $category->slug]);

                // Check for error
                if (!is_wp_error($category_id)) {
                    add_term_meta($category_id['term_id'], 'wix_id', $category->id);
                }
            }
        }

        // Get posts
        $get_posts = $this->get_posts($params);

        // Check for error
        if (is_wp_error($get_posts)) {
            return $get_posts;
        }

        // Loop through posts
        $posts = $get_posts['posts'];

        foreach ($posts as $item) {
            // Create post
            $post_id = $this->insert_post($item);

            // If post is created successfully
            if ($post_id) {

                // Insert post metas
                $this->insert_metas($post_id, $item);

                // Insert post categories
                $this->insert_categories($post_id, $item);

                // Insert post tags
                $this->insert_tags($post_id, $item);

                // Import post images
                $this->import_post_images($post_id);

                // Import post featured image
                if ($item['coverMedia']['image']['url']) {
                    $attachment_id = $this->import_image_by_url($item['coverMedia']['image']['url'], $post_id);

                    // If attachment is created successfully set as featured image
                    if ($attachment_id) {
                        set_post_thumbnail($post_id, $attachment_id);
                    }
                }

                // Fires after a post has been successfully inserted.
                do_action('WixBlogImport/after_insert_post', $post_id, $item);
            }
        }
    }

    /**
     * Insert Post
     * 
     * @param array $item Post Item
     * 
     * @return int|WP_Error
     */
    public function insert_post($item)
    {
        // Get item content
        $content = $item['content'];

        // Decode content
        $content_decode = json_decode($content, true);

        // Get entity map
        $entity_map = $content_decode['entityMap'];

        // Modify entity item for DraftConverter
        if ($entity_map) {
            foreach ($entity_map as $key => $value) {
                if ($value['type'] == 'wix-draft-plugin-image') {
                    $entity_map[$key]['type'] = 'IMAGE';
                    $entity_map[$key]['data']['url'] = $this->static_media_url . '/' . $value['data']['src']['id'];

                    unset($entity_map[$key]['data']['src']);
                }
            }

            $content_decode['entityMap'] = $entity_map;
        }

        // Encode content
        $content = json_encode($content_decode);

        // Convert content to HTML
        $content = DraftConverter::convertFromJson($content);
        $converter = new Converter;
        $content = $converter
            ->setState($content)
            ->toHtml();

        // Create post
        $post_data = [
            'post_title' => $item['title'],
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => $item['firstPublishedDate'],
        ];


        /**
         * Filters post data before inserting post
         */
        $post_data = apply_filters('WixBlogImport/post_data', $post_data, $item);

        $post_id = wp_insert_post($post_data);

        return $post_id;
    }


    /**
     * Insert Post Categories
     * 
     * @param int $post_id Post ID
     * @param array $item Post Item
     * 
     * @return void
     */
    public function insert_categories($post_id, $item)
    {
        // Get item categories
        $categories = $item['categoryIds'];

        if (!$categories) {
            return;
        }

        // Loop through categories
        // $category_id = wix id
        foreach ($categories as $category_id) {
            // Find category by wix_id
            $term_args = array(
                'hide_empty' => false,
                'meta_query' => array(
                    array(
                        'key'       => 'wix_id',
                        'value'     => $category_id,
                        'compare'   => '='
                    )
                ),
                'taxonomy'  => 'category',
            );

            $terms = get_terms($term_args);

            if (!empty($terms)) {
                $terms = array_column($terms, 'term_id');
                wp_set_post_categories($post_id, $terms);
            }
        }
    }

    /**
     * Insert Post Tags
     * 
     * @param int $post_id Post ID
     * @param array $item Post Item
     * 
     * @return void
     */
    public function insert_tags($post_id, $item)
    {
        // Get item tags
        $tags = $item['tagIds'];

        if (!$tags) {
            return;
        }

        // Keep added tags in array
        $added_tags = [];

        // Loop through tags
        foreach ($tags as $tag_id) {
            // Get tag data
            $get_tag = $this->get_tag($tag_id);

            // Check for error
            if (is_wp_error($get_tag)) {
                continue;
            }

            $tag = $get_tag->tag;

            // push tag label to added tags array
            $added_tags[] = $tag->label;
        }

        // Add tags to post
        wp_set_post_tags($post_id, $added_tags);
    }

    /**
     * Insert Post Metas
     * 
     * @param int $post_id Post ID
     * @param array $item Post Item
     * 
     * @return void
     */
    public function insert_metas($post_id, $item)
    {
        do_action('WixBlogImport/insert_metas', $post_id, $item);

        add_post_meta($post_id, 'wix_id', $item['id']);
    }

    /**
     * Save to server images from post content and replace images url with local images url
     * 
     * @param int $post_id Post ID
     * 
     * @return void
     */
    public function import_post_images($post_id)
    {
        // Get post content
        $post_content = get_post_field('post_content', $post_id);

        // Find all <img tags in content
        $post_content = preg_replace_callback('/<img[^>]+>/i', function ($matches) use ($post_id) {
            // Get <img tag
            $img = $matches[0];

            // Get image url
            $src = preg_match('/src="([^"]+)"/i', $img, $match);
            $src = $match[1];

            // Import image to server
            $attachment_id = $this->import_image_by_url($src, $post_id);

            // If attachment is created successfully change image url to local url
            if ($attachment_id) {
                $img = str_replace($src, wp_get_attachment_url($attachment_id), $img);
            }

            return $img;
        }, $post_content);

        // Update post content
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $post_content,
        ]);
    }

    /**
     * Download image from url and save to server
     * 
     * @param string $url Image url
     * @param int $post_id Post ID
     * 
     * @return int|WP_Error
     */
    public function import_image_by_url($url, $post_id)
    {
        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($url);
        $file_ext = pathinfo($url, PATHINFO_EXTENSION);

        // Extract file name from image url
        $filename = preg_match('/media\/(.*?)~/', $url, $match);
        $filename = $match[1];
        $filename = $filename . '.' . $file_ext;

        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }

        // check if the file already exists
        if (file_exists($file)) {
            // attachment id
            $file_url = $upload_dir['url'] . '/' . $filename;
            $attachment_id = attachment_url_to_postid($file_url);

            return $attachment_id;
        }

        // Save image to server
        file_put_contents($file, $image_data);

        $wp_filetype = wp_check_filetype($filename, null);

        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert attachment to database
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);

        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }
}
