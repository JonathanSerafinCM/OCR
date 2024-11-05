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

    private function preprocessMenuText($text)
    {
        $lines = explode("\n", $text);
        $newLines = [];
        $previousLine = '';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // 1. Eliminar líneas sin letras o cortas
            if (!preg_match('/[a-zA-ZáéíóúÁÉÍÓÚñÑ]/u', $trimmedLine) || strlen($trimmedLine) <= 2) {
                continue;
            }

            // 2. Unir líneas que pertenecen al mismo plato
            $shouldMerge = false;
            if (!empty($previousLine)) {
                $shouldMerge =
                    preg_match('/^[\p{Ll}\p{P}\s»]/u', $trimmedLine) || // Empieza con minúscula, símbolo o »
                    preg_match('/[,;]\s*$/u', $previousLine) ||        // La línea anterior termina en coma o punto y coma
                    !preg_match('/[.!?]\s*$/u', $previousLine) ||       // La línea anterior no termina en puntuación final
                    !preg_match('/[A-ZÁÉÍÓÚÑ]/u', $trimmedLine) ||        // No tiene mayúsculas (probablemente continuación)
                    preg_match('/^(?:con|y|de|del|la|las|los|el|en|al|por)\s/i', $trimmedLine); // Empieza con conectores comunes

                if (!preg_match('/\d+(?:[.,]\d{2})?\s*(?:€|EUR)/u', $trimmedLine) && 
                    preg_match('/\d+(?:[.,]\d{2})?\s*(?:€|EUR)/u', $previousLine) && 
                    $shouldMerge) {
                    $shouldMerge = false; //Evita unir precios separados
                }

                if ($shouldMerge) {
                    $newLines[count($newLines) - 1] .= ' ' . $trimmedLine;
                    $previousLine = $newLines[count($newLines) - 1];
                    continue;
                }
            }

            $newLines[] = $trimmedLine;
            $previousLine = $trimmedLine;
        }

        return implode("\n", $newLines);
    }

    private function parseMenuText($text)
    {
        try {
            $cleanedText = $this->cleanMenuText($text);
            $preprocessedText = $this->preprocessMenuText($cleanedText);
            
            // Split menu into categories
            $categories = $this->splitMenuIntoCategories($preprocessedText);
            
            $allResults = [
                'categories' => [],
                'general_notes' => ''
            ];

            // Process each category separately
            foreach ($categories as $categoryName => $categoryContent) {
                $categoryPrompt = $this->buildOpenAIPrompt($categoryContent);
                $categoryResult = $this->processWithOpenAI($categoryPrompt);
                
                if (isset($categoryResult->categories[0])) {
                    $allResults['categories'][] = $categoryResult->categories[0];
                }
            }

            return [
                'structured_menu' => $allResults,
                'items' => $this->convertToItems($allResults)
            ];

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

        return json_decode($response->choices[0]->message->content);
    }

    private function buildOpenAIPrompt(string $menuText): string
    {
        $prompt = <<<EOT
        Eres un experto en análisis de menús de restaurantes. Extrae cada plato, su precio y descripción (generada de acuerdo al nombre del platillo). 
        Crea descripciones cortas si no existen. Los nombres de platos pueden ser largos. 
        Si un plato no tiene descripción, genera una corta basada en su nombre.

        **Formato JSON:**
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
                      "price": "Precio del plato",
                      "description": "Descripción del plato (generada)",
                      "special_notes": "Notas especiales (opcional)",
                      "discount": "Descuento (opcional)",
                      "additional_details": "Información adicional (opcional)"
                    }
                  ]
                }
              ]
            }
          ],
          "general_notes": "Notas generales del menú (opcional)"
        }

        **Ejemplos:**

        --- Tapas ---
        Patatas bravas con alioli y salsa picante - 7.50
        Patatas fritas crujientes con dos salsas caseras.

        Croquetas de jamón ibérico con reducción de Pedro Ximénez - 9.00
        Crujientes por fuera, cremosas por dentro, con un toque dulce.

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
        $dishPattern = '/^(.+?)\s+(\d+(?:[.,]\d{2})?\s*(?:€|USD|[\p{Sc}]|EUR)?)(?:\s*-\s*(\d+(?:[.,]\d{2})?\s*(?:€|USD|[\p{Sc}]|EUR)?))?/iu';
        
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



