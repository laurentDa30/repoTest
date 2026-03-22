<?php

namespace App\Jobs;

use App\Models\Setting;
use App\Models\SocialPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSocialPosts implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $hashtag = Setting::get('social_hashtag', '#monsalon');

        if (Setting::get('instagram_enabled', '1') === '1') {
            $this->syncInstagram($hashtag);
        }

        if (Setting::get('facebook_enabled', '1') === '1') {
            $this->syncFacebook($hashtag);
        }
    }

    private function syncInstagram(string $hashtag): void
    {
        $accessToken = config('services.instagram.token');
        if (!$accessToken) {
            Log::info('Instagram access token not configured.');
            return;
        }

        try {
            // Instagram Basic Display API / Graph API
            $response = Http::get('https://graph.instagram.com/me/media', [
                'fields' => 'id,caption,media_url,permalink,timestamp,media_type',
                'access_token' => $accessToken,
                'limit' => (int) Setting::get('posts_count', '5'),
            ]);

            if ($response->successful()) {
                $posts = $response->json('data', []);
                $cleanHashtag = ltrim($hashtag, '#');

                foreach ($posts as $post) {
                    $caption = $post['caption'] ?? '';
                    if (stripos($caption, $cleanHashtag) === false) {
                        continue;
                    }

                    if (in_array($post['media_type'] ?? '', ['IMAGE', 'CAROUSEL_ALBUM'])) {
                        SocialPost::updateOrCreate(
                            ['post_id' => $post['id']],
                            [
                                'platform' => 'instagram',
                                'image_url' => $post['media_url'],
                                'caption' => $caption,
                                'post_url' => $post['permalink'],
                                'posted_at' => $post['timestamp'],
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Instagram sync failed: ' . $e->getMessage());
        }
    }

    private function syncFacebook(string $hashtag): void
    {
        $accessToken = config('services.facebook.token');
        $pageId = config('services.facebook.page_id');

        if (!$accessToken || !$pageId) {
            Log::info('Facebook credentials not configured.');
            return;
        }

        try {
            $response = Http::get("https://graph.facebook.com/v18.0/{$pageId}/posts", [
                'fields' => 'id,message,full_picture,permalink_url,created_time',
                'access_token' => $accessToken,
                'limit' => (int) Setting::get('posts_count', '5'),
            ]);

            if ($response->successful()) {
                $posts = $response->json('data', []);
                $cleanHashtag = ltrim($hashtag, '#');

                foreach ($posts as $post) {
                    $message = $post['message'] ?? '';
                    if (stripos($message, $cleanHashtag) === false) {
                        continue;
                    }

                    if (!empty($post['full_picture'])) {
                        SocialPost::updateOrCreate(
                            ['post_id' => $post['id']],
                            [
                                'platform' => 'facebook',
                                'image_url' => $post['full_picture'],
                                'caption' => $message,
                                'post_url' => $post['permalink_url'] ?? '',
                                'posted_at' => $post['created_time'],
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Facebook sync failed: ' . $e->getMessage());
        }
    }
}
