<?php

namespace Bonnier\WP\ContentHub\Editor\Commands;

use Bonnier\Willow\MuPlugins\Helpers\LanguageProvider;
use Bonnier\WP\Cache\Models\Post as BonnierCachePost;
use Bonnier\WP\ContentHub\Editor\Commands\Taxonomy\Helpers\WpTerm;
use Bonnier\WP\ContentHub\Editor\Models\WpAttachment;
use Bonnier\WP\ContentHub\Editor\Models\WpComposite;
use Bonnier\WP\ContentHub\Editor\Models\WpTaxonomy;
use Bonnier\WP\ContentHub\Editor\Repositories\Scaphold\CompositeRepository;
use Bonnier\WP\Cxense\Models\Post as CxensePost;
use Bonnier\WP\Redirect\Model\Post;
use Illuminate\Support\Collection;
use WP_CLI;
use WP_Post;

/**
 * Class AdvancedCustomFields
 *
 * @package \Bonnier\WP\ContentHub\Commands
 */
class Composites extends BaseCmd
{
    const CMD_NAMESPACE = 'composites';

    public static function register()
    {
        WP_CLI::add_command(CmdManager::CORE_CMD_NAMESPACE . ' ' . static::CMD_NAMESPACE, __CLASS__);
    }

    /**
     * Imports composites from Scaphold
     *
     * ## OPTIONS
     *
     *
     * [--id=<id>]
     * : The id of a single composite to import.
     *
     * [--source=<source_code>]
     * : The the source code to fetch content from
     *
     * ## EXAMPLES
     * wp contenthub editor composites import
     *
     * @param $args
     * @param $assocArgs
     */
    public function import($args, $assocArgs)
    {
        // Disable generation of image sizes on import to speed up the precess
        add_filter('intermediate_image_sizes_advanced', function ($sizes) {
            return [];
        });

        // Disable on save hook to prevent call to content hub, Cxense and Bonnier Cache Manager
        remove_action('save_post', [WpComposite::class, 'on_save'], 10);
        remove_action('save_post', [BonnierCachePost::class, 'update_post'], 10);
        remove_action('save_post', [CxensePost::class, 'update_post'], 10);
        remove_action('save_post', [Post::class, 'save'], 5);

        if ($compositeId = $assocArgs['id'] ?? null) {
            $this->importComposite(CompositeRepository::findById($compositeId));
            return;
        }
        $brandId = $this->get_site()->brand->content_hub_id;
        if ($source = $assocArgs['source'] ?? null) {
            $this->mapCompositesByBrandIdAndSource($brandId, $source, function ($composite) {
                $this->importComposite($composite);
            });
            return;
        }
        $this->mapCompositesByBrandId($brandId, function ($composite) {
            $this->importComposite($composite);
        });
    }

    /**
     * Removes imported composites that no longer exist on content hub
     *
     * ## OPTIONS
     *
     *
     * [--id=<id>]
     * : The id of a single composite to import.
     *
     * ## EXAMPLES
     * wp contenthub editor composites clean_orphaned
     *
     * @param $args
     * @param $assocArgs
     */
    public function clean_orphaned($args, $assocArgs)
    {
        WP_CLI::line('Beginning clean of orphaned composites');

        $queryArgs = [
            'post_type' => 'contenthub_composite',
            'posts_per_page' => 100,
            'paged' => 0
        ];

        $posts = query_posts($queryArgs);

        while ($posts) {
            collect($posts)->each(function (WP_Post $post) {
                $this->removeIfOrphaned($post);
            });

            $queryArgs['paged']++;
            $posts = query_posts($queryArgs);
        }

        WP_CLI::success('Done');
    }

    private function mapCompositesByBrandId($brandId, callable $callable)
    {
        $compositeQuery = CompositeRepository::findByBrandId($brandId);

        while ($compositeQuery) {
            $categories = collect($compositeQuery->edges);
            $categories->pluck('node')->each(function ($compositeInfo) use ($callable) {
                $callable(CompositeRepository::findById($compositeInfo->id));
            });
            if (isset($compositeQuery->pageInfo->hasNextPage) && $compositeQuery->pageInfo->hasNextPage) {
                $compositeQuery = CompositeRepository::findByBrandId($brandId, $categories->last()->cursor);
            } else {
                $compositeQuery = null;
            }
        }
    }

    private function mapCompositesByBrandIdAndSource($brandId, $source, callable $callable)
    {
        $compositeQuery = CompositeRepository::findByBrandIdAndSource($brandId, $source);

        while ($compositeQuery) {
            $categories = collect($compositeQuery->edges);
            $categories->pluck('node')->each(function ($compositeInfo) use ($callable) {
                $callable(CompositeRepository::findById($compositeInfo->id));
            });
            if (isset($compositeQuery->pageInfo->hasNextPage) && $compositeQuery->pageInfo->hasNextPage) {
                $compositeQuery = CompositeRepository::findByBrandIdAndSource(
                    $brandId,
                    $source,
                    $categories->last()->cursor
                );
            } else {
                $compositeQuery = null;
            }
        }
    }

    private function importComposite($composite)
    {
        WP_CLI::line('Beginning import of: ' . $composite->title  . ' id: ' . $composite->id);

        $postId = $this->createPost($composite);
        $compositeContents = $this->formatCompositeContents($composite);

        $this->handleTranslation($postId, $composite);
        $this->setMeta($postId, $composite);
        $this->deleteOrphanedFiles($postId, $compositeContents);
        $this->saveCompositeContents($postId, $compositeContents);
        $this->saveTags($postId, $compositeContents);
        $this->saveTeasers($postId, $composite);
        $this->setSlug($postId, $composite);
        $this->handleLockedContent($postId, $composite);
        $this->saveCategories($postId, $composite);

        WP_CLI::success('imported: ' . $composite->title  . ' id: ' . $composite->id);
    }

    private function createPost($composite)
    {
        $existingId = WpComposite::id_from_contenthub_id($composite->id);

        // Tell Polylang the language of the post to allow multiple posts with the same slug in different languages
        $_POST['term_lang_choice'] = $composite->locale;

        return wp_insert_post([
            'ID' => $existingId,
            'post_title' => $composite->title,
            'post_name' => $this->getPostName($composite),
            'post_status' => collect([
                'Published' => 'publish',
                'Draft' => 'draft',
                'Ready' => 'pending'
            ])->get($composite->status, 'draft'),
            'post_type' => WpComposite::POST_TYPE,
            'post_date' => $composite->publishedAt ?? $composite->createdAt,
            'post_modified' => $composite->modifiedAt,
            'meta_input' => [
                WpComposite::POST_META_CONTENTHUB_ID => $composite->id,
                WpComposite::POST_META_TITLE => $composite->metaInformation->pageTitle,
                WpComposite::POST_META_DESCRIPTION => $composite->metaInformation->description,
                WpComposite::POST_CANONICAL_URL => $composite->metaInformation->canonicalUrl,
            ],
        ]);
    }

    private function handleTranslation($postId, $composite)
    {
        LanguageProvider::setPostLanguage($postId, $composite->locale);
        if (isset($composite->translationSet->composites->edges)) {
            LanguageProvider::savePostTranslations( // Link translations together
                collect($composite->translationSet->composites->edges)
                    ->pluck('node')
                    ->map(function ($compositeTranslation) {
                        // Get local post id
                        if ($localId = WpComposite::id_from_contenthub_id($compositeTranslation->id)) {
                            return [$compositeTranslation->locale, $localId];
                        }
                        return null;
                    })
                    ->rejectNullValues()
                    ->toAssoc()
                    ->toArray() // returns something like ['da' => 232, 'sv' => 231]
            );
        }
    }

    private function setMeta($postId, $composite)
    {
        update_field('kind', $composite->kind, $postId);
        update_field('description', $composite->description, $postId);

        if ($composite->magazine) {
            $magazineYearIssue = explode('-', $composite->magazine);
            update_field('magazine_year', $magazineYearIssue[0], $postId);
            update_field('magazine_issue', $magazineYearIssue[1], $postId);
        }


        update_field('commercial', isset($composite->advertorial_type), $postId);
        update_field('commercial_type', $composite->advertorial_type ?? null, $postId);


        //update_field('internal_comment', $composite->metaInformation->internalComment ?? null, $postId);
    }

    private function formatCompositeContents($composite)
    {
        return collect($composite->content->edges)
            ->pluck('node')
            ->map(function ($compositeContent) {
                // Convert into collection of collections
                return collect($compositeContent)
                    ->rejectNullValues();
            })->map(function (Collection $compositeContent) {
                $contentType = $compositeContent
                    ->except(['id', 'position', 'locked'])
                    ->keys()
                    ->first();
                return (object) $compositeContent
                    ->only(['id', 'position', 'locked'])
                    ->merge([
                        'type' => snake_case($contentType),
                        'content' => $compositeContent->get($contentType)
                    ])->toArray();
            });
    }

    private function saveCompositeContents($postId, Collection $compositeContents)
    {
        $content = $compositeContents
            ->map(function ($compositeContent) use ($postId) {
                if ($compositeContent->type === 'text_item') {
                    return [
                        'body' => $compositeContent->content->body,
                        'locked_content' => $compositeContent->locked,
                        'acf_fc_layout' => $compositeContent->type
                    ];
                }
                if ($compositeContent->type === 'image') {
                    return [
                        'lead_image' => $compositeContent->content->trait === 'Primary' ? true : false,
                        'file' => WpAttachment::upload_attachment($postId, $compositeContent->content),
                        'locked_content' => $compositeContent->locked,
                        'acf_fc_layout' => $compositeContent->type
                    ];
                }
                if ($compositeContent->type === 'file') {
                    return [
                        'file' => WpAttachment::upload_attachment($postId, $compositeContent->content),
                        'images' => collect($compositeContent->content->images->edges)
                            ->map(function ($image) use ($postId) {
                                return [
                                    'file' => WpAttachment::upload_attachment($postId, $image->node),
                                ];
                            }),
                        'locked_content' => $compositeContent->locked,
                        'acf_fc_layout' => $compositeContent->type
                    ];
                }
                if ($compositeContent->type === 'inserted_code') {
                    return [
                        'code' => $compositeContent->content->code,
                        'locked_content' => $compositeContent->locked,
                        'acf_fc_layout' => $compositeContent->type
                    ];
                }
                return null;
            })
            ->rejectNullValues();

        update_field('composite_content', $content->toArray(), $postId);
    }

    private function saveTags($postId, Collection $compositeContents)
    {
        collect($compositeContents
            ->map(function ($compositeContent) {
                if ($compositeContent->type === 'tag' &&
                    $existingTermId = WpTerm::id_from_contenthub_id($compositeContent->content->id)
                ) {
                    if (isset($compositeContent->content->vocabulary->id) &&
                        $existingTaxonomy = WpTaxonomy::get_taxonomy($compositeContent->content->vocabulary->id)
                    ) {
                        return [$existingTaxonomy => $existingTermId];
                    }
                    return ['tags' => $existingTermId];
                }
                return null;
            }))
            ->rejectNullValues()
            ->toAssocCombine()
            ->each(function (Collection $tagIds, $taxonomy) use ($postId) {
                update_field($taxonomy, $tagIds->toArray(), $postId);
            });
    }

    private function saveTeasers($postId, $composite)
    {
        collect($composite->teasers->edges)
            ->pluck('node')
            ->each(function ($teaser) use ($postId) {
                if ($teaser->kind === 'Internal') {
                    update_field('teaser_title', $teaser->title, $postId);
                    update_field('teaser_description', $teaser->description, $postId);
                    update_field('teaser_image', WpAttachment::upload_attachment($postId, $teaser->image), $postId);
                }
                if ($teaser->kind === 'Facebook') {
                    update_post_meta($postId, WpComposite::POST_FACEBOOK_TITLE, $teaser->title);
                    update_post_meta($postId, WpComposite::POST_FACEBOOK_DESCRIPTION, $teaser->description);
                    if ($teaser->image) {
                        $imageId = WpAttachment::upload_attachment($postId, $teaser->image);
                        update_post_meta(
                            $postId,
                            WpComposite::POST_FACEBOOK_IMAGE,
                            wp_get_attachment_image_url($imageId)
                        );
                    }
                }
                // Todo: implement Twitter social teaser
            });
    }

    private function setSlug($postId, $composite)
    {
        if ($originalSlug = parse_url($composite->metaInformation->originalUrl)['path'] ?? null) {
            // Ensure that post has the same url as it previously had
            $currentSlug = parse_url(get_permalink($postId))['path'];
            if (rtrim($originalSlug, '/') !== rtrim($currentSlug, '/')) {
                update_post_meta($postId, WpComposite::POST_META_CUSTOM_PERMALINK, ltrim($originalSlug, '/'));
            }
        }
    }

    private function handleLockedContent($postId, $composite)
    {
        $accessRules = collect($composite->accessRules->edges)->pluck('node');
        if (!$accessRules->isEmpty()) {
            update_field(
                'locked_content',
                $accessRules->first(function ($rule) {
                    return $rule->domain === 'All' && $rule->kind === 'Deny';
                }) ? true : false,
                $postId
            );
            update_field(
                'required_user_role',
                $accessRules->first(function ($rule) {
                    return in_array($rule->domain, ['Subscriber', 'RegUser']) && $rule->kind === 'Allow';
                })->domain,
                $postId
            );
        }
    }

    private function saveCategories($postId, $composite)
    {
        collect($composite->categories->edges)->pluck('node')->each(function ($category) use ($postId) {
            if ($existingTermId = WpTerm::id_from_contenthub_id($category->id)) {
                update_field('category', $existingTermId, $postId);
            }
        });
    }

    private function removeIfOrphaned(WP_Post $post)
    {
        $compositeId = get_post_meta($post->ID, WpComposite::POST_META_CONTENTHUB_ID, true);
        if ($compositeId && !CompositeRepository::findById($compositeId)) {
            // Delete attachments on composite
            collect(get_field('composite_content', $post->ID) ?? [])->each(function ($content) {
                if ($content['acf_fc_layout'] === 'file') {
                    wp_delete_attachment($content['file']['ID'], true);
                    collect($content['images'])->each(function ($image) {
                        wp_delete_attachment($image['file']['ID'], true);
                    });
                }
                if ($content['acf_fc_layout'] === 'image') {
                    wp_delete_attachment($content['file']['ID'], true);
                }
            });
            // Delete composite
            wp_delete_post($post->ID, true);
            WP_CLI::line(sprintf(
                'Removed post: %s, with id:%s and composite id:%s',
                $post->post_title,
                $post->ID,
                $compositeId
            ));
        }
    }

    private function getPostName($composite)
    {
        if (preg_match('/[^\/]*$/', $composite->metaInformation->originalUrl, $matches) && !empty($matches)) {
            return $matches[0]; // return the part of the slug after the last /
        }
        global $locale; // No original url is available so we generate post name from the title instead
        $locale = $composite->locale; // We modify the global $locale so sanitize_title_with_dashes() works correctly
        return sanitize_title($composite->title);
    }

    /**
     * @param                                $postId
     * @param \Illuminate\Support\Collection $compositeContents
     *
     * Deletes attachments that would have otherwise become orphaned after import
     */
    private function deleteOrphanedFiles($postId, Collection $compositeContents)
    {
        $currentFileIds = collect(get_field('composite_content', $postId))
            ->map(function ($content) use ($postId) {
                if ($content['acf_fc_layout'] === 'image') {
                    return WpAttachment::contenthub_id($content['file'] ?? null);
                }
                if ($content['acf_fc_layout'] === 'file') {
                    return [
                        'file'   => WpAttachment::contenthub_id($content['file'] ?? null),
                        'images' => collect($content['images'])
                            ->map(function ($image) {
                                return WpAttachment::contenthub_id($image['file'] ?? null);
                            })
                    ];
                }
                return null;
            })
            ->flatten()
            ->push(WpAttachment::contenthub_id(get_field('teaser_image', $postId)))
            ->rejectNullValues();

        $newFileIds = $compositeContents
            ->map(function ($compositeContent) {
                if ($compositeContent->type === 'image') {
                    return $compositeContent->content->id;
                }
                if ($compositeContent->type === 'file') {
                    return [
                        'file'   => $compositeContent->content->id,
                        'images' => collect($compositeContent->content->images->edges)->map(function ($image) {
                            return $image->node->id;
                        })
                    ];
                }
                return null;
            })
            ->flatten()
            ->rejectNullValues();

        // Compare current file ids to new file ids
        $currentFileIds->diff($newFileIds)
            ->each(function ($orphanedFileId) {
                // We delete any of the current files that would be come orphaned
                WpAttachment::delete_by_contenthub_id($orphanedFileId);
            });
    }
}
