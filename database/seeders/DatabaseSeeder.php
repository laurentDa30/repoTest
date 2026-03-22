<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Service;
use App\Models\Setting;
use App\Models\SocialPost;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::create([
            'name' => 'Admin',
            'email' => 'admin@salon.com',
            'password' => Hash::make('password'),
        ]);

        // Services
        $services = [
            ['category' => 'Femme', 'name' => 'Coupe femme', 'description' => 'Coupe, shampoing et brushing', 'price' => 45, 'duration' => 60, 'sort_order' => 1],
            ['category' => 'Femme', 'name' => 'Brushing', 'description' => 'Shampoing et brushing', 'price' => 25, 'duration' => 30, 'sort_order' => 2],
            ['category' => 'Femme', 'name' => 'Coupe + Couleur', 'description' => 'Coupe complète avec coloration', 'price' => 85, 'duration' => 120, 'sort_order' => 3],
            ['category' => 'Homme', 'name' => 'Coupe homme', 'description' => 'Coupe classique ou moderne', 'price' => 25, 'duration' => 30, 'sort_order' => 1],
            ['category' => 'Homme', 'name' => 'Coupe + Barbe', 'description' => 'Coupe et taille de barbe', 'price' => 35, 'duration' => 45, 'sort_order' => 2],
            ['category' => 'Enfant', 'name' => 'Coupe enfant', 'description' => 'Moins de 12 ans', 'price' => 15, 'duration' => 20, 'sort_order' => 1],
            ['category' => 'Coloration', 'name' => 'Coloration complète', 'description' => 'Coloration racines et longueurs', 'price' => 55, 'duration' => 90, 'sort_order' => 1],
            ['category' => 'Coloration', 'name' => 'Mèches / Balayage', 'description' => 'Effet naturel et lumineux', 'price' => 70, 'duration' => 120, 'sort_order' => 2],
            ['category' => 'Soins', 'name' => 'Soin profond', 'description' => 'Masque nourrissant et hydratant', 'price' => 20, 'duration' => 30, 'sort_order' => 1],
            ['category' => 'Soins', 'name' => 'Kératine', 'description' => 'Lissage à la kératine', 'price' => 120, 'duration' => 150, 'sort_order' => 2],
            ['category' => 'Barbe', 'name' => 'Taille de barbe', 'description' => 'Taille et soin de la barbe', 'price' => 15, 'duration' => 20, 'sort_order' => 1],
            ['category' => 'Barbe', 'name' => 'Rasage traditionnel', 'description' => 'Rasage au coupe-chou', 'price' => 20, 'duration' => 30, 'sort_order' => 2],
        ];

        foreach ($services as $service) {
            Service::create($service);
        }

        // Default settings
        Setting::set('social_hashtag', '#monsalon');
        Setting::set('instagram_enabled', '1');
        Setting::set('facebook_enabled', '1');
        Setting::set('posts_count', '5');
        Setting::set('salon_name', 'Cris Création');
        Setting::set('salon_address', '123 Rue de la Beauté, 75001 Paris');
        Setting::set('salon_phone', '01 23 45 67 89');
        Setting::set('salon_email', 'contact@criscreation.fr');
        Setting::set('opening_hours', "Lundi: Fermé\nMardi - Vendredi: 9h00 - 19h00\nSamedi: 9h00 - 18h00\nDimanche: Fermé");

        // Demo social posts
        $demoPosts = [
            ['platform' => 'instagram', 'post_id' => 'demo_1', 'image_url' => 'https://images.unsplash.com/photo-1560066984-138dadb4c035?w=600', 'caption' => 'Transformation du jour ! Balayage naturel pour un look lumineux. #monsalon', 'post_url' => '#', 'posted_at' => now()->subDays(1)],
            ['platform' => 'instagram', 'post_id' => 'demo_2', 'image_url' => 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=600', 'caption' => 'Coupe moderne et élégante. Venez découvrir nos experts ! #monsalon', 'post_url' => '#', 'posted_at' => now()->subDays(2)],
            ['platform' => 'facebook', 'post_id' => 'demo_3', 'image_url' => 'https://images.unsplash.com/photo-1595476108010-b4d1f102b1b1?w=600', 'caption' => 'Notre salon vous accueille dans un cadre chaleureux. #monsalon', 'post_url' => '#', 'posted_at' => now()->subDays(3)],
            ['platform' => 'instagram', 'post_id' => 'demo_4', 'image_url' => 'https://images.unsplash.com/photo-1562322140-8baeececf3df?w=600', 'caption' => 'Coloration tendance de la saison. Résultat sublime ! #monsalon', 'post_url' => '#', 'posted_at' => now()->subDays(4)],
            ['platform' => 'facebook', 'post_id' => 'demo_5', 'image_url' => 'https://images.unsplash.com/photo-1521590832167-7bcbfaa6381f?w=600', 'caption' => 'Des soins capillaires d\'exception pour sublimer vos cheveux. #monsalon', 'post_url' => '#', 'posted_at' => now()->subDays(5)],
        ];

        foreach ($demoPosts as $post) {
            SocialPost::create($post);
        }
    }
}
