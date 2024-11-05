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
            $menuData = $this->parseMenuText($text);

            if (isset($menuData['items'])) {
                foreach ($menuData['items'] as $item) {
                    Menu::create($item);
                }
            }

            Storage::disk('local')->delete($path);
            if (isset($outputPath)) {
                Storage::disk('local')->delete('temp/output-1.png');
            }

            return response()->json([
                'message' => 'Menu procesado exitosamente',
                'items_count' => count($menuData['items'] ?? []),
                'menu' => $menuData['structured_menu'] ?? null,
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

    private function cleanMenuText($text) {
        // Eliminar caracteres especiales y números al inicio de las líneas
        $text = preg_replace('/^[\d»\*\s]+/m', '', $text);
        // Eliminar caracteres especiales al final de las líneas
        $text = preg_replace('/_+\s*$|Q\s*\/|\(\s*Y\s*\)/m', '', $text);
        // Normalizar precios
        $text = preg_replace('/(\d+)[.,](\d{2})\s*€?/', '$1.$2', $text);
        // Limpiar líneas vacías múltiples
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return $text;
    }

    private function parseMenuText($text)
    {
        try {
            // Debug del texto limpio
            $cleanedText = $this->cleanMenuText($text);
            Log::debug('Cleaned text before OpenAI:', ['text' => $cleanedText]);
            
            // Verificar la API key
            Log::debug('OpenAI API Key:', ['key' => substr(config('services.openai.api_key'), 0, 10) . '...']);

            try {
                $response = $this->openai->chat()->create([
                    'model' => 'gpt-3.5-turbo-16k',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Eres un experto chef analizando menús de restaurantes.'
                        ],
                        [
                            'role' => 'user',
                            'content' => "Analiza este menú y devuelve un JSON. Ejemplo del formato esperado:
                        {
                            \"categories\": [
                                {
                                    \"name\": \"ENTRANTES\",
                                    \"dishes\": [
                                        {
                                            \"name\": \"Patatas Bravas\",
                                            \"price\": 6.50,
                                            \"description\": \"Crujientes patatas con salsa brava casera\"
                                        }
                                    ]
                                }
                            ]
                        }

                        Menú a analizar:\n{$cleanedText}"
                        ]
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 2000
                ]);

                // Debug de la respuesta completa de OpenAI
                Log::debug('OpenAI Raw Response:', ['response' => $response]);
                
                $content = $response->choices[0]->message->content;
                Log::debug('OpenAI Content:', ['content' => $content]);
                
                // Verificar si el contenido parece JSON válido
                if (!str_starts_with(trim($content), '{')) {
                    Log::error('OpenAI response is not JSON:', ['content' => $content]);
                    throw new \Exception('OpenAI response is not in JSON format');
                }

                $menuData = json_decode($content, true);
                
                // Debug del JSON decodificado
                Log::debug('Decoded JSON:', ['data' => $menuData]);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('JSON decode error:', [
                        'error' => json_last_error_msg(),
                        'content' => $content
                    ]);
                    throw new \Exception('Failed to parse JSON: ' . json_last_error_msg());
                }

                if (!isset($menuData['categories']) || empty($menuData['categories'])) {
                    Log::error('Missing or empty categories:', ['data' => $menuData]);
                    throw new \Exception('Invalid menu structure: missing categories');
                }

                // Convertir la estructura al formato de la base de datos
                $menuItems = [];
                foreach ($menuData['categories'] as $category) {
                    foreach ($category['dishes'] as $dish) {
                        $menuItems[] = [
                            'category' => $category['name'],
                            'dish_name' => $dish['name'],
                            'price' => floatval(str_replace(',', '.', $dish['price'])),
                            'description' => $dish['description'],
                            'special_notes' => ''
                        ];
                    }
                }

                return [
                    'structured_menu' => $menuData,
                    'items' => $menuItems
                ];

            } catch (\Exception $e) {
                Log::error('OpenAI API error:', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Menu parsing failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Intentar usar el parser legacy
            return $this->useLegacyParser($text);
        }
    }

    private function useLegacyParser($text)
    {
        Log::info('Falling back to legacy parser');
        $legacyItems = $this->legacyParseMenuText($text);
        
        // Debug de items encontrados
        Log::debug('Legacy parser results:', ['items' => $legacyItems]);
        
        // Convertir al nuevo formato
        $structuredMenu = [
            'categories' => []
        ];
        
        $categorizedItems = [];
        foreach ($legacyItems as $item) {
            $category = $item['category'] ?: 'OTROS';
            if (!isset($categorizedItems[$category])) {
                $categorizedItems[$category] = [];
            }
            $categorizedItems[$category][] = [
                'name' => $item['dish_name'],
                'price' => $item['price'],
                'description' => $item['description'] ?: 'Plato tradicional de nuestra carta'
            ];
        }
        
        foreach ($categorizedItems as $categoryName => $dishes) {
            $structuredMenu['categories'][] = [
                'name' => $categoryName,
                'dishes' => $dishes
            ];
        }

        return [
            'structured_menu' => $structuredMenu,
            'items' => $legacyItems
        ];
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
