<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenAI;
use JsonSchema\Validator as JsonSchemaValidator;

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
                    // Asegurar que todos los campos opcionales tengan un valor por defecto
                    $menuItem = [
                        'category' => $item['category'] ?? 'Sin categoría',
                        'subcategory' => $item['subcategory'] ?? null,
                        'dish_name' => $item['dish_name'] ?? '',
                        'price' => $item['price'] ?? 0,
                        'description' => $item['description'] ?? '',
                        'special_notes' => $item['special_notes'] ?? '',
                        'discount' => $item['discount'] ?? null,
                        'additional_details' => $item['additional_details'] ?? null
                    ];
                    Menu::create($menuItem);
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
                'general_notes' => $menuData['structured_menu']['general_notes'] ?? null,
                'raw_text' => app()->environment('local') ? $text : null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Menu processing failed', [
                'error' => 'Error al procesar el menú: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Error al procesar el menú: ' . $e->getMessage(),
                'details' => app()->environment('local') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    private function cleanMenuText($text) {
        // Eliminar caracteres no imprimibles
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $text);
        
        // Eliminar caracteres especiales y números al inicio de las líneas
        $text = preg_replace('/^[\d»\*\s]+/m', '', $text);
        
        // Eliminar caracteres especiales al final de las líneas
        $text = preg_replace('/_+\s*$|Q\s*\/|\(\s*Y\s*\)|\s*=+\s*$|-+\s*$/m', '', $text);
        
        // Normalizar precios
        $text = preg_replace('/(\d+)[.,](\d{2})\s*€?/', '$1.$2', $text);
        
        // Normalizar caracteres especiales
        $text = str_replace(['8', '&'], 'Y', $text);
        
        // Limpiar líneas vacías múltiples y espacios
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/\s+/', ' ', $text);
        
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

            // Construir el prompt actualizado
            $prompt = $this->buildOpenAIPrompt($cleanedText);

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
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 3000
                ]);

                // Debug de la respuesta completa de OpenAI
                Log::debug('OpenAI Raw Response:', ['response' => $response]);
                
                $content = $response->choices[0]->message->content;
                Log::debug('OpenAI Content:', ['content' => $content]);
                
                // Validación con JSON Schema
                $validator = new JsonSchemaValidator();
                $schema = json_decode(file_get_contents(resource_path('schemas/menu_schema.json')));
                $data = json_decode($content);

                $validator->validate($data, $schema);

                if ($validator->isValid()) {
                    // Convertir la estructura al formato de la base de datos
                    if (isset($data->categories)) {
                        foreach ($data->categories as $category) {
                            foreach ($category->subcategories as $subcategory) {
                                foreach ($subcategory->dishes as $dish) {
                                    $menuItem = [
                                        'category' => $category->name ?? 'Sin categoría',
                                        'subcategory' => $subcategory->name ?? null,
                                        'dish_name' => $dish->name ?? '',
                                        'price' => $dish->price ?? 0,
                                        'description' => $dish->description ?? '',
                                        'special_notes' => $dish->special_notes ?? '',
                                        'discount' => $dish->discount ?? null,
                                        'additional_details' => $dish->additional_details ?? null
                                    ];
                                    Menu::create($menuItem);
                                }
                            }
                        }
                    }
                } else {
                    Log::error('Invalid JSON Schema', ['errors' => $validator->getErrors()]);
                    throw new \Exception('Error de esquema JSON');
                }

                return [
                    'message' => 'Menu procesado exitosamente',
                    'items_count' => count($data->categories ?? []),
                    'menu' => $data->general_notes ?? null,
                    'general_notes' => $data->general_notes ?? null,
                    'raw_text' => app()->environment('local') ? $text : null
                ];

            } catch (\OpenAI\Exceptions\ApiException $e) {
                // Log del error y fallback al legacy parser
                Log::error('OpenAI API Error', ['error' => $e->getMessage()]);
                return $this->useLegacyParser($text);
            } catch (\Throwable $e) {
                Log::error('Menu parsing failed', ['error' => $e->getMessage()]);
                return $this->useLegacyParser($text);
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

    private function buildOpenAIPrompt(string $menuText): string
    {
        $prompt = <<<EOT
        Eres un experto en análisis de menús de restaurantes. Tu tarea es analizar el texto de un menú y extraer la información de cada plato, incluyendo nombre, precio, descripción, etc. Si encuentras un precio en un rango, usa el precio más alto del rango. Debes devolver la información en formato JSON.

        **Formato JSON:**

        {
          "categories": [
            {
              "name": "Nombre de la categoría",
              "subcategories": [
                {
                  "name": "Nombre de la subcategoría",
                  "dishes": [
                    {
                      "name": "Nombre del plato",
                      "price": "Precio",
                      "description": "Descripción",
                      "special_notes": "Notas especiales",
                      "discount": "Descuento",
                      "additional_details": "Detalles adicionales"
                    }
                  ]
                }
              ]
            }
          ],
          "general_notes": "Notas generales del menú"
        }

        **Ejemplos:**

        --- Entrantes ---
        Ensalada César 8.50
        Lechuga romana, pollo, crutones, queso parmesano

        Sopa de tomate 5.00
        Tomates frescos, albahaca

        --- Platos principales ---
        Pasta Carbonara 12.00
        Spaghetti, panceta, huevo, queso pecorino

        Pizza Margarita 10.00
        Salsa de tomate, mozzarella, albahaca

        **Menú a analizar:**

        {$menuText}
        EOT;
        return $prompt;
    }

    private function useLegacyParser($text)
    {
        Log::info('Falling back to legacy parser');
        $legacyItems = $this->legacyParseMenuText($text);
        
        Log::debug('Legacy parser results:', ['items' => $legacyItems]);
        
        // Convertir al nuevo formato
        $structuredMenu = [
            'categories' => []
        ];
        
        $categorizedItems = [];
        foreach ($legacyItems as $item) {
            $category = $item['category'] ?: 'Sin categoría';
            if (!isset($categorizedItems[$category])) {
                $categorizedItems[$category] = [
                    'name' => $category,
                    'subcategories' => [
                        [
                            'name' => $item['subcategory'] ?? 'General',
                            'dishes' => []
                        ]
                    ]
                ];
            }
            
            $categorizedItems[$category]['subcategories'][0]['dishes'][] = [
                'name' => $item['dish_name'],
                'price' => $item['price'],
                'description' => $item['description'] ?: '',
                'special_notes' => $item['special_notes'] ?: '',
                'discount' => $item['discount'] ?: '',
                'additional_details' => $item['additional_details'] ?: ''
            ];
        }
        
        $structuredMenu['categories'] = array_values($categorizedItems);

        return [
            'structured_menu' => $structuredMenu,
            'items' => $legacyItems
        ];
    }

    private function legacyParseMenuText($text)
    {
        $items = [];
        $lines = explode("\n", $text);
        $currentCategory = '';
        $currentSubcategory = '';
        
        // Patrones mejorados para detectar categorías y platos
        $categoryPattern = '/^[A-ZÁÉÍÓÚÑ\s]{3,}$/u';
        $dishPattern = '/^(.+?)\s+(\d+(?:[.,]\d{2})?\s*(?:€|USD|[\p{Sc}])?)(?:\s*-\s*(\d+(?:[.,]\d{2})?\s*(?:€|USD|[\p{Sc}])?))?/iu';
        
        for ($index = 0; $index < count($lines); $index++) {
            $trimmedLine = trim($lines[$index]);
            if (empty($trimmedLine)) continue;

            // Detectar categorías
            if (preg_match($categoryPattern, $trimmedLine)) {
                $currentCategory = $trimmedLine;
                continue;
            }

            // Detectar platos con precios
            if (preg_match($dishPattern, $trimmedLine, $matches)) {
                $dishName = trim($matches[1]);
                $price = isset($matches[3]) ? max(floatval(str_replace(',', '.', $matches[2])), floatval(str_replace(',', '.', $matches[3]))) : str_replace(',', '.', $matches[2]);

                // Extraer descripción del plato (líneas siguientes)
                $description = '';
                $nextIndex = $index + 1;
                while (isset($lines[$nextIndex]) && 
                       !preg_match($categoryPattern, trim($lines[$nextIndex])) && 
                       !preg_match($dishPattern, trim($lines[$nextIndex]))) {
                    $nextLine = trim($lines[$nextIndex]);
                    if (!empty($nextLine)) {
                        $description .= ' ' . $nextLine;
                    }
                    $nextIndex++;
                }
                $index = $nextIndex - 1;

                // Detectar notas especiales
                $specialNotes = '';
                if (preg_match('/\*([^*]+)$/', $description, $noteMatches)) {
                    $specialNotes = trim($noteMatches[1]);
                    $description = trim(str_replace($noteMatches[0], '', $description));
                }

                // Detectar descuentos
                $discount = '';
                if (preg_match('/(2x1|[0-9]+%\s*dto\.?|oferta)/i', $dishName, $discountMatch)) {
                    $discount = $discountMatch[0];
                    $dishName = trim(str_replace($discountMatch[0], '', $dishName));
                }

                $items[] = [
                    'category' => $currentCategory ?: 'Sin categoría',
                    'subcategory' => $currentSubcategory ?: null,
                    'dish_name' => $dishName,
                    'price' => $price,
                    'description' => trim($description),
                    'special_notes' => $specialNotes,
                    'discount' => $discount,
                    'additional_details' => null
                ];
            }
        }

        return $items;
    }
}



