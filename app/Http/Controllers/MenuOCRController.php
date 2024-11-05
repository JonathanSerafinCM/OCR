<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenAI;

class MenuOCRController extends Controller
{
    private $openai;

    public function __construct()
    {
        $this->openai = OpenAI::client(config('services.openai.api_key'));
    }

    public function processMenu(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'menu_file' => 'required|file|mimes:jpg,png,pdf|max:10240'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'details' => $validator->errors()
                ], 422);
            }

            Log::info('Processing menu file', ['filename' => $request->file('menu_file')->getClientOriginalName()]);

            $file = $request->file('menu_file');
            $path = Storage::disk('local')->putFile('temp', $file);
            $fullPath = Storage::disk('local')->path($path);
            
            if ($file->getClientOriginalExtension() === 'pdf') {
                Log::info('Converting PDF to image');
                $outputPath = Storage::disk('local')->path('temp/output');
                exec("pdftoppm -png {$fullPath} {$outputPath} 2>&1", $output, $returnCode);
                
                if ($returnCode !== 0) {
                    Log::error('PDF conversion failed', ['output' => $output, 'code' => $returnCode]);
                    throw new \Exception('PDF conversion failed');
                }
                
                $fullPath = $outputPath . '-1.png';
            }

            Log::info('Running OCR on file', ['path' => $fullPath]);
            
            $text = (new TesseractOCR($fullPath))
                ->lang('spa')
                ->run();

            if (empty($text)) {
                throw new \Exception('OCR resulted in empty text');
            }

            Log::info('OCR completed, parsing text');
            $menuItems = $this->parseMenuText($text);

            foreach ($menuItems as $item) {
                Menu::create($item);
            }

            Storage::disk('local')->delete($path);
            if (isset($outputPath)) {
                Storage::disk('local')->delete('temp/output-1.png');
            }

            return response()->json([
                'message' => 'Menu processed successfully',
                'items' => count($menuItems),
                'text' => $text // Debug only, remove in production
            ]);
            
        } catch (\Exception $e) {
            Log::error('Menu processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => $e->getMessage(),
                'details' => app()->environment('local') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    private function parseMenuText($text)
    {
        try {
            $response = $this->openai->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant that processes restaurant menu text and returns it in a structured format. Extract dishes with their categories, names, prices, and generate appealing descriptions.'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Please analyze this menu text and return a JSON array where each item has: category, dish_name, price, and description. Generate an appetizing description for each dish: \n\n" . $text
                    ]
                ],
                'temperature' => 0.7,
            ]);

            $content = $response->choices[0]->message->content;
            $menuItems = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to parse OpenAI response', ['content' => $content]);
                throw new \Exception('Failed to parse menu structure');
            }

            return $menuItems;

        } catch (\Exception $e) {
            Log::error('OpenAI processing failed', [
                'error' => $e->getMessage(),
                'text' => $text
            ]);
            
            // Fallback to original parsing if AI fails
            return $this->legacyParseMenuText($text);
        }
    }

    private function legacyParseMenuText($text)
    {
        // Move the original parsing logic here as fallback
        $items = [];
        $lines = explode("\n", $text);
        $currentCategory = '';
        
        foreach ($lines as $line) {
            if (preg_match('/^[A-ZÁÉÍÓÚÑ\s]{3,}$/', trim($line))) {
                $currentCategory = trim($line);
                continue;
            }

            if (preg_match('/^(.+?)\s*(\d{1,3}(?:,\d{2})?(?:\.\d{2})?)\s*€?$/', $line, $matches)) {
                $items[] = [
                    'category' => $currentCategory,
                    'dish_name' => trim($matches[1]),
                    'price' => (float) str_replace(',', '.', $matches[2]),
                    'description' => '',
                    'special_notes' => ''
                ];
            }
        }

        return $items;
    }
}
