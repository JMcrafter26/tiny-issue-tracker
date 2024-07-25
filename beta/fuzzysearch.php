<?php

/**
 * Perform a fuzzy search on an array of strings and return the results with match percentages.
 * Measure the execution time in microseconds.
 * Author: JMcrafter26
 *
 * @param string $query The search query.
 * @param array $array The array of strings to search within.
 * @return array The results with match percentages and execution time.
 */
function fuzzySearch($query, $array) {
    $start_time = microtime(true); // Start the timer
    
    $results = [];
    $query = strtolower($query); // Convert query to lowercase
    $queryLength = strlen($query); // Pre-calculate the length of the query
    
    // Define a function to calculate match percentage using Levenshtein distance
    function getMatchPercentage($query, $item, $queryLength) {
        $itemLength = strlen($item); // Pre-calculate the length of the item
        $levDistance = levenshtein($query, $item);
        $maxLen = max($queryLength, $itemLength);
        if ($maxLen == 0) {
            return 1; // both strings are empty
        }
        return 1 * (1 - ($levDistance / $maxLen));
    }
    
    // Loop through each item in the array and calculate the match percentage
    foreach ($array as $item) {
        $lowerItem = strtolower($item); // Convert item to lowercase
        
        // Early exit for exact matches
        if ($query === $lowerItem) {
            $results[] = [
                'item' => $item,
                'match_percentage' => 1
            ];
            continue;
        }
        
        $percentage = getMatchPercentage($query, $lowerItem, $queryLength);
        $results[] = [
            'item' => $item,
            'match_percentage' => $percentage
        ];
    }
    
    // Sort the results by match percentage in descending order
    usort($results, function($a, $b) {
        return $b['match_percentage'] <=> $a['match_percentage'];
    });
    
    $end_time = microtime(true); // End the timer
    
    $execution_time = ($end_time - $start_time) * 1000000; // Convert to microseconds
    
    return [
        'results' => $results,
        'execution_time_microseconds' => $execution_time
    ];
}

// Example usage
$query = "Apple";
$array = ["apple", "Pineapple", "banana", "grape", "aple", "Appl"];
$response = fuzzySearch($query, $array);

// Print the results
foreach ($response['results'] as $result) {
    echo "Item: " . $result['item'] . " - Match: " . $result['match_percentage'] . "\n";
}

echo "Execution Time: " . $response['execution_time_microseconds'] . " microseconds\n";
?>