<?php

namespace App\Http\Controllers;

use App\Models\Test;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated download of a Test's lab CSV. The file lives on the PRIVATE
 * 'local' disk (storage/app/private/csv), so it is never web-accessible by URL.
 * Reaching it requires an authenticated admin session (the route is behind the
 * 'auth' middleware, same web guard Filament uses) and the file is always served
 * as a download (Content-Disposition: attachment, forced text/csv) so it can't
 * render in-browser.
 */
class TestCsvController extends Controller
{
    public function download(Test $test): StreamedResponse
    {
        // Admins only — any authenticated panel user (web guard). Never public.
        abort_unless(auth()->check(), 403);

        abort_if(blank($test->csv_path) || ! Storage::disk('local')->exists($test->csv_path), 404);

        $filename = 'test-'.($test->order_id ?: $test->getKey()).'.csv';

        return Storage::disk('local')->download($test->csv_path, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
