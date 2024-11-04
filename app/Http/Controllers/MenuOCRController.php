<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MenuOCRController extends Controller
{
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
        $items = [];
        $lines = explode("\n", $text);
        $currentCategory = '';
        
        foreach ($lines as $line) {
            // Detectar categorías (generalmente en mayúsculas)
            if (preg_match('/^[A-ZÁÉÍÓÚÑ\s]{3,}$/', trim($line))) {
                $currentCategory = trim($line);
                continue;
            }

            // Detectar platos y precios
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
