<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #1907 — the Livewire temporary-upload endpoint must not stage
 * scriptable or non-allowlisted types.
 *
 * `Tests\TestCase` disables throttling; CSRF is skipped in tests. The
 * endpoint validates a RELATIVE signature, so generate the URL relatively.
 */
class LivewireTempUploadTest extends TestCase
{
    private function uploadUrl(): string
    {
        return URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], false);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function upload(array $files): TestResponse
    {
        return $this->post($this->uploadUrl(), ['files' => $files], ['Accept' => 'application/json']);
    }

    public function test_rejects_svg(): void
    {
        Storage::fake('tmp-for-tests');

        $this->upload([UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
    }

    public function test_rejects_html(): void
    {
        Storage::fake('tmp-for-tests');

        $this->upload([UploadedFile::fake()->create('x.html', 1, 'text/html')])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
    }

    public function test_rejects_php(): void
    {
        Storage::fake('tmp-for-tests');

        $this->upload([UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('tmp-for-tests')->allFiles());
    }

    public function test_accepts_jpg(): void
    {
        Storage::fake('tmp-for-tests');

        $this->upload([UploadedFile::fake()->create('x.jpg', 10, 'image/jpeg')])
            ->assertOk()
            ->assertJsonStructure(['paths']);

        $this->assertNotEmpty(Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'));
    }

    public function test_accepts_xlsx(): void
    {
        Storage::fake('tmp-for-tests');

        $this->upload([UploadedFile::fake()->create(
            'x.xlsx',
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        )])->assertOk()->assertJsonStructure(['paths']);
    }

    public function test_unsigned_request_is_401(): void
    {
        $path = (string) parse_url(route('livewire.upload-file'), PHP_URL_PATH);

        $this->post($path, [
            'files' => [UploadedFile::fake()->create('x.jpg', 10, 'image/jpeg')],
        ])->assertStatus(401);
    }

    public function test_preview_mimes_no_longer_lists_svg(): void
    {
        /** @var array<int, string> $mimes */
        $mimes = config('livewire.temporary_file_upload.preview_mimes');

        $this->assertNotContains('svg', $mimes);
    }
}
