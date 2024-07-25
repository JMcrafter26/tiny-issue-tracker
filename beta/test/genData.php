<?php

// Generate random data for testing the fuzzy search algorithm
function genData($numItems, $minLength, $maxLength) {
    $data = [];
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    for ($i = 0; $i < $numItems; $i++) {
        $length = rand($minLength, $maxLength);
        $item = '';
        for ($j = 0; $j < $length; $j++) {
            $item .= $chars[rand(0, strlen($chars) - 1)];
        }
        $data[] = $item;
    }
    return $data;
}

// run the function and save it to data.json
$data = genData(1000, 5, 15);
file_put_contents('data.json', json_encode($data));