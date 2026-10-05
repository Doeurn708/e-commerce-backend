<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Product images go to Cloudinary, so the SDK is mocked here: the suite must
 * never reach the real API, and it must pass without valid credentials in .env.
 *
 * The mock returns a public_id under the `products` folder so the URL-parsing
 * helpers in CloudinaryImageStore can recover it, which is what lets a replace
 * or delete find the old asset again.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private string $cloud = 'qmallj8e';

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway file is enough: the SDK never reads it while mocked.
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Fake the Cloudinary SDK. $counter makes every upload return a distinct
     * public_id, so a replace is observable. $destroyed collects the public ids
     * handed to destroy(), which is how a cleanup is asserted.
     *
     * @param  list<string>  $destroyed
     */
    private function fakeCloudinary(array &$destroyed = []): void
    {
        $counter = 0;

        $uploadApi = Mockery::mock();
        $uploadApi->shouldReceive('upload')->andReturnUsing(function () use (&$counter) {
            $publicId = 'products/photo-'.(++$counter);

            return (object) [
                'secure_url' => "https://res.cloudinary.com/{$this->cloud}/image/upload/{$publicId}.jpg",
                'public_id' => $publicId,
            ];
        });
        $uploadApi->shouldReceive('destroy')->andReturnUsing(function (string $publicId) use (&$destroyed) {
            $destroyed[] = $publicId;

            return (object) ['result' => 'ok'];
        });

        $cloudinary = Mockery::mock();
        $cloudinary->shouldReceive('uploadApi')->andReturn($uploadApi);

        Cloudinary::swap($cloudinary);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function category(): Category
    {
        return Category::create([
            'name' => 'Shoes',
            'slug' => 'shoes',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(Category $category): array
    {
        return [
            'category_id' => $category->id,
            'name' => 'Runner',
            'description' => 'Light and fast.',
            'price' => '49.99',
            'stock' => '10',
        ];
    }

    public function test_it_uploads_the_image_and_stores_the_url_and_public_id(): void
    {
        $this->fakeCloudinary();
        $category = $this->category();
        $this->admin();

        $response = $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->image('runner.jpg'),
        ]);

        $response->assertCreated();

        $product = Product::firstOrFail();

        $this->assertSame(
            "https://res.cloudinary.com/{$this->cloud}/image/upload/products/photo-1.jpg",
            $product->image,
        );
        $this->assertSame($product->image, $product->image_url);
        $this->assertSame('products/photo-1', $product->image_public_id);

        // The response hands the frontend everything it needs to show the image.
        $response->assertJsonPath('data.image_public_id', 'products/photo-1')
            ->assertJsonPath('data.image_url', $product->image);
    }

    public function test_it_rejects_a_file_that_is_not_an_image(): void
    {
        $this->fakeCloudinary();
        $category = $this->category();
        $this->admin();

        // The Accept header mirrors the Axios client: it is what makes Laravel answer
        // a validation failure with 422 JSON instead of a 302 redirect. The
        // body still has to be multipart, so this cannot be postJson().
        $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_rejects_an_image_larger_than_two_megabytes(): void
    {
        $this->fakeCloudinary();
        $category = $this->category();
        $this->admin();

        $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(3000),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_it_rejects_an_upload_from_a_non_admin_user(): void
    {
        $this->fakeCloudinary();
        $category = $this->category();
        $this->actingAs(User::factory()->create(['role' => 'customer']), 'sanctum');

        $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->image('runner.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_replacing_the_image_removes_the_previous_asset(): void
    {
        $destroyed = [];
        $this->fakeCloudinary($destroyed);
        $category = $this->category();
        $this->admin();

        $productId = $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->image('first.jpg'),
        ])->json('data.id');

        // Nothing to clean up yet: the first upload is the only asset.
        $this->assertSame([], $destroyed);

        // POST, not PUT: PHP only parses multipart bodies on POST.
        $response = $this->post("/api/products/{$productId}", $this->payload($category) + [
            'image' => UploadedFile::fake()->image('second.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();

        $product = Product::findOrFail($productId);

        $this->assertSame('products/photo-2', $product->image_public_id);
        $this->assertStringContainsString('products/photo-2.jpg', $product->image);

        // The replaced asset is gone from Cloudinary, so it is not orphaned.
        $this->assertContains('products/photo-1', $destroyed);
        $this->assertNotContains('products/photo-2', $destroyed);
    }

    public function test_updating_without_an_image_keeps_the_current_one(): void
    {
        $destroyed = [];
        $this->fakeCloudinary($destroyed);
        $category = $this->category();
        $this->admin();

        $productId = $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->image('runner.jpg'),
        ])->json('data.id');

        // array_merge, not +, so the new name actually replaces the payload's.
        $this->post("/api/products/{$productId}", array_merge($this->payload($category), [
            'name' => 'Runner v2',
        ]), ['Accept' => 'application/json'])->assertOk();

        $product = Product::findOrFail($productId);

        $this->assertSame('Runner v2', $product->name);
        $this->assertSame('products/photo-1', $product->image_public_id);

        // A text-only edit must not destroy the image it is keeping.
        $this->assertSame([], $destroyed);
    }

    public function test_deleting_a_product_removes_its_asset(): void
    {
        $destroyed = [];
        $this->fakeCloudinary($destroyed);
        $category = $this->category();
        $this->admin();

        $productId = $this->post('/api/products', $this->payload($category) + [
            'image' => UploadedFile::fake()->image('runner.jpg'),
        ])->json('data.id');

        $this->deleteJson("/api/products/{$productId}")->assertOk();

        $this->assertDatabaseCount('products', 0);
        $this->assertContains('products/photo-1', $destroyed);
    }
}