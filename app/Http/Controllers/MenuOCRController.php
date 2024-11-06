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

            if (isset($menuData['structured_menu']['categories'])) {
                $itemsCount = 0;
                foreach ($menuData['structured_menu']['categories'] as $category) {
                    foreach ($category['subcategories'] as $subcategory) {
                        foreach ($subcategory['dishes'] as $dish) {
                            Menu::create([
                                'category' => $category['name'],
                                'subcategory' => $subcategory['name'] ?? null,
                                'dish_name' => $dish['name'],
                                'price' => $dish['price'],
                                'description' => $dish['description'] ?? ''
                            ]);
                            $itemsCount++;
                        }
                    }
                }

                return response()->json([
                    'message' => 'Menu procesado exitosamente',
                    'items_count' => $itemsCount,
                    'menu' => $menuData['structured_menu'],
                    'raw_text' => app()->environment('local') ? $text : null
                ]);
            }

            throw new \Exception('No se pudo procesar el menú correctamente');

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
        // 1. Eliminar caracteres no imprimibles
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F\x{2500}-\x{257F}]/u', '', $text);

        // 2. Reemplazar múltiples espacios
        $text = preg_replace('/\s+/', ' ', $text);

        // 3. Eliminar espacios alrededor de signos
        $text = preg_replace('/\s*([-,:;])\s*/', '$1', $text);

        // 4. Normalizar precios
        $text = preg_replace('/(\d+)(?:[.,](\d{2}))?\s*(?:[€$]|[\p{Sc}])?\s*-\s*(\d+)(?:[.,](\d{2}))?\s*(?:[€$]|[\p{Sc}])?/u', '$1.$2-$3.$4', $text);
        $text = preg_replace('/(\d+)[.,](\d{2})\s*(?:[€$]|[\p{Sc}])?/u', '$1.$2', $text);

        return trim($text);
    }

    private function preprocessMenuText($text)
    {
        $lines = explode("\n", $text);
        $newLines = [];
        $currentDish = '';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (preg_match('/\d+(?:[.,]\d{2})?(?:\s*(?:-|a)\s*\d+(?:[.,]\d{2})?)?\s*[\p{Sc}]?/u', $trimmedLine)) {
                if (!empty($currentDish)) {
                    $newLines[] = $currentDish;
                }
                $currentDish = $trimmedLine;
            } elseif (!empty($trimmedLine) && !empty($currentDish)) {
                $currentDish .= ' ' . $trimmedLine;
            } elseif (!empty($trimmedLine)) {
                $newLines[] = $trimmedLine;
            }
        }

        if (!empty($currentDish)) {
            $newLines[] = $currentDish;
        }

        return implode("\n", $newLines);
    }

    private function parseMenuText($text)
    {
        try {
            $cleanedText = $this->cleanMenuText($text);
            $preprocessedText = $this->preprocessMenuText($cleanedText);
            $categories = $this->splitMenuIntoCategories($preprocessedText);
            $allResults = ['categories' => []];

            foreach ($categories as $categoryName => $categoryContent) {
                try {
                    $categoryPrompt = $this->buildOpenAIPrompt($categoryContent);
                    $categoryResult = $this->processWithOpenAI($categoryPrompt);
                    $decodedResult = json_decode($categoryResult, true);

                    if ($decodedResult && isset($decodedResult['categories'][0])) {
                        $allResults['categories'][] = $decodedResult['categories'][0];
                    } else {
                        Log::error('OpenAI returned invalid JSON', [
                            'category' => $categoryName,
                            'response' => $categoryResult
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Category processing failed', [
                        'category' => $categoryName,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return ['structured_menu' => $allResults];

        } catch (\Exception $e) {
            Log::error('Menu parsing failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->useLegacyParser($text);
        }
    }

    private function splitMenuIntoCategories($text)
    {
        $lines = explode("\n", $text);
        $categories = [];
        $currentCategory = 'General';
        $currentContent = '';

        foreach ($lines as $line) {
            if (preg_match('/^---\s*(.+?)\s*---$/', $line, $matches)) {
                if (!empty($currentContent)) {
                    $categories[$currentCategory] = trim($currentContent);
                }
                $currentCategory = $matches[1];
                $currentContent = '';
            } else {
                $currentContent .= $line . "\n";
            }
        }

        if (!empty($currentContent)) {
            $categories[$currentCategory] = trim($currentContent);
        }

        return $categories;
    }

    private function processWithOpenAI($prompt)
    {
        $response = $this->openai->chat()->create([
            'model' => 'gpt-3.5-turbo-16k', // Upgraded to GPT-4
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
            'temperature' => 0.3,
            'max_tokens' => 3000
        ]);

        return $response->choices[0]->message->content;
    }

    private function buildOpenAIPrompt(string $menuText): string
    {
        return <<<EOT
        Eres un experto en análisis de menús de restaurantes. Tu tarea es extraer la información de los platos y organizarla en formato JSON.
        Cada línea del menú representa un plato con su precio o información adicional. El precio es el indicador CLAVE de un nuevo plato.

        Reglas:
        1. Un plato SIEMPRE tiene precio. Formato: número (10), decimal (10.50), o rango (10-12) posiblemente seguido de un símbolo de moneda.
        2. Líneas SIN precio: títulos, descripciones generales o continuaciones del plato ANTERIOR si la línea anterior SÍ tenía precio.
        3. Nombres de platos largos: ¡No los dividas! Un plato puede ocupar varias palabras antes del precio.
        4. Crea descripciones concisas para cada plato, incluso si no hay una explícita.

        Formato JSON:
        {
          "categories": [
            {
              "name": "Nombre de la categoría",
              "subcategories": [
                {
                  "name": "Nombre de la subcategoría (opcional)",
                  "dishes": [
                    {
                      "name": "Nombre completo del plato (puede ser largo)",
                      "price": "Precio del plato (con o sin símbolo de moneda)",
                      "description": "Descripción concisa y atractiva"
                    }
                  ]
                }
              ]
            }
          ]
        }

        Menú:
        {$menuText}
        EOT;
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
        
        $categoryPattern = '/^[A-ZÁÉÍÓÚÑ\s]{3,}$/u';
        $dishPattern = '/^(.+?)(?:\s+|_+)(\d+(?:[.,]\d{2})?(?:\s*(?:€|USD|[\p{Sc}]|EUR)?)(?:\s*-\s*\d+(?:[.,]\d{2})?(?:\s*(?:€|USD|[\p{Sc}]|EUR)?)?)?)/u';
        
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



