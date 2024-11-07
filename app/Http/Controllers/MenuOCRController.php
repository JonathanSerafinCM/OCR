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
            
            // Convert PDF to image if needed
            if ($file->getClientOriginalExtension() === 'pdf') {
                $fullPath = $this->convertPdfToImage($fullPath);
            }

            // Perform OCR
            $text = $this->performOCR($fullPath);
            
            // Process text with OpenAI
            $menuItems = $this->extractMenuItems($text);
            
            // Save to database
            $savedItems = $this->saveMenuItems($menuItems);

            return response()->json([
                'message' => 'Menu processed successfully',
                'items_count' => count($savedItems),
                'items' => $savedItems,
                'raw_text' => app()->environment('local') ? $text : null
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

    private function convertPdfToImage($pdfPath)
    {
        Log::info('Converting PDF to image');
        $outputPath = Storage::disk('local')->path('temp/output');
        exec("pdftoppm -png {$pdfPath} {$outputPath}", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('PDF conversion failed');
        }
        
        return $outputPath . '-1.png';
    }

    private function performOCR($imagePath)
    {
        Log::info('Running OCR on file', ['path' => $imagePath]);
        
        $text = (new TesseractOCR($imagePath))
            ->lang('spa')
            ->run();

        if (empty($text)) {
            throw new \Exception('OCR resulted in empty text');
        }

        return $this->cleanText($text);
    }

    private function cleanText($text)
    {
        // 1. Eliminar caracteres no imprimibles y símbolos gráficos
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F\x{2500}-\x{257F}]/u', '', $text);
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $text);
        
        // Replace multiple spaces/newlines with single ones
        $text = preg_replace('/\s+/', ' ', $text);
        
        $text = preg_replace('/\s*([-,:;.])\s*/', '$1', $text);

        // 4. Normalizar precios
        $text = preg_replace('/(\d+)(?:[.,](\d{2}))?\s*(?:[€$]|[\p{Sc}])?\s*-\s*(\d+)(?:[.,](\d{2}))?\s*(?:[€$]|[\p{Sc}])?/u', '$1.$2-$3.$4', $text);
        $text = preg_replace('/(\d+)[.,](\d{2})\s*(?:[€$]|[\p{Sc}])?/u', '$1.$2', $text);

        // 5. Eliminar texto que se repite mucho
        $text = preg_replace('/\*+/', '*', $text);

        // Reglas importantes para las descripciones:
        $text = preg_replace('/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ\s\*\-\.,]/u', '', $text);

        return trim($text);
    }

    private function buildOpenAIPrompt($text, $attempt = 0)
    {
        // Add variation based on retry attempt
        $variation = ($attempt > 0) 
            ? "\n(Intento {$attempt} - IMPORTANTE: Genera SOLO JSON válido)" 
            : "";

        return <<<EOT
        Analiza este menú y devuelve SOLO un array JSON. Sin texto adicional.

        [
          {
            "name": "Nombre del plato",
            "price": "12.50",
            "description": "Descripción breve",
            "category": "Categoría"
          }
        ]

        Reglas:
        1. Solo n��meros y punto decimal en precio
        2. Nombre y precio son obligatorios
        3. Genera descripción si falta
        4. Categoriza los platos

        Menú:{$variation}
        {$text}
        EOT;
    }

    private function extractMenuItems($text)
    {
        $maxRetries = 3;
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            try {
                $prompt = $this->buildOpenAIPrompt($text, $attempt);
                Log::debug('Sending prompt to OpenAI:', ['attempt' => $attempt + 1, 'prompt' => $prompt]);

                $response = $this->openai->chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Eres un parser de menús. Devuelve SOLO JSON válido.'
                        ],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => $attempt > 0 ? 0.5 : 0.3,
                    'max_tokens' => 2000
                ]);

                $content = $response->choices[0]->message->content;
                Log::debug('Raw OpenAI response:', ['content' => $content]);

                // Clean and validate JSON
                $content = $this->cleanAndValidateJSON($content);
                Log::debug('Cleaned JSON:', ['content' => $content]);

                $menuItems = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON decode error: ' . json_last_error_msg());
                }

                // Validate menu items
                return $this->validateMenuItems($menuItems);

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error('Attempt ' . ($attempt + 1) . ' failed:', [
                    'error' => $lastError,
                    'response' => $content ?? null
                ]);

                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw new \Exception("Failed after {$maxRetries} attempts: {$lastError}");
                }

                sleep(1);
            }
        }
    }

    private function cleanAndValidateJSON($content)
    {
        // Remove any text before first '[' or '{'
        $content = preg_replace('/^[^[{]*/s', '', $content);
        
        // Remove any text after last ']' or '}'
        $content = preg_replace('/[^\]\}]+$/s', '', $content);

        // If content starts with '{', wrap it in array
        if (str_starts_with(trim($content), '{')) {
            $content = '[' . $content . ']';
        }

        // Ensure it's a valid JSON array structure
        if (!preg_match('/^\s*\[[\s\S]*\]\s*$/', $content)) {
            throw new \Exception('Invalid JSON array structure');
        }

        return $content;
    }

    private function validateMenuItems($items)
    {
        if (!is_array($items) || empty($items)) {
            throw new \Exception('No menu items found in response');
        }

        return array_map(function($item) {
            if (!isset($item['name']) || !isset($item['price'])) {
                throw new \Exception('Missing required fields');
            }

            return [
                'name' => trim($item['name']),
                'price' => preg_replace('/[^0-9.]/', '', $item['price']),
                'description' => $item['description'] ?? 'Sin descripción',
                'category' => $item['category'] ?? 'Sin Categoría'
            ];
        }, $items);
    }

    private $keywords = [
        'Entradas' => ['entrante', 'aperitivo', 'para picar', 'tapa'],
        'Ensaladas' => ['ensalada', 'mezclum', 'lechuga', 'tomate'],
        'Carnes' => ['carne', 'pollo', 'ternera', 'cerdo', 'pescado', 'bistec', 'filete'],
        'Postres' => ['postre', 'dulce', 'pastel', 'tarta', 'helado'],
        // ...más categorías
    ];

    private function assignCategory(string $dishName, string $dishDescription, array $keywords): ?string
    {
        foreach ($keywords as $category => $categoryKeywords) {
            foreach ($categoryKeywords as $keyword) {
                if (stripos($dishName, $keyword) !== false || stripos($dishDescription, $keyword) !== false) {
                    return $category;
                }
            }
        }
        return null; // No se encontró una categoría
    }

    private function saveMenuItems($menuItems)
    {
        $savedItems = [];
        
        foreach ($menuItems as $item) {
            $category = $item['category'] ?? null;
            
            if (!$category) {
                $assignedCategory = $this->assignCategory($item['name'], $item['description'], $this->keywords);
                if ($assignedCategory) {
                    $category = $assignedCategory;
                } else {
                    $category = 'Sin Categoría';
                }
            }

            // Verifica si dish_name existe ANTES de intentar insertar
            if (isset($item['name']) && !empty(trim($item['name']))) { 
                $savedItems[] = Menu::create([
                    'dish_name' => $item['name'], // Changed from 'name' to 'dish_name'

                    'price' => $item['price'],
                    'description' => $item['description'] ?? null,
                    'category' => $category
                ]);
            } else {
                // Maneja la ausencia de dish_name
                Log::warning('Platillo omitido, sin nombre:', ['dish' => $item]);
            }
        }

        return $savedItems;
    }
}






