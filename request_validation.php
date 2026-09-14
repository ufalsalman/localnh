<?php
// Validate only fields consumed by the application; unrelated query keys remain compatible.
// Run before database/session work so malformed scalar fields fail without side effects.
function localnh_validate_request_inputs(): void {
    $query = ['q','a','artist','t','tag','c','circ','circle','p','parody','char','ch','character','l','lang','language','page','sort','cat','id','w','mode','download','action','toggle','logout'];
    // CSRF token shape is checked by the authorization guard, preserving its 403 response.
    $form = ['action','user','pass','toggle','logout'];
    foreach ([[$_GET, $query], [$_POST, $form]] as [$values, $names]) {
        foreach ($names as $name) {
            if (!array_key_exists($name, $values)) continue;
            $value = $values[$name];
            if (!is_string($value) || strlen($value) > 4096 || str_contains($value, "\0")) {
                http_response_code(400);
                header('Cache-Control: no-store');
                exit('invalid request field.');
            }
        }
    }
    // Preferences are untrusted browser input. Invalid values use the existing defaults.
    foreach (['dark_mode'=>['on','off'], 'dynamic_color'=>['on','off'], 'blur_covers'=>['on','off'], 'flip_page_order'=>['ltr','rtl'], 'home_sort'=>['na','nu']] as $key=>$allowed) {
        if (isset($_COOKIE[$key]) && !in_array($_COOKIE[$key], $allowed, true)) unset($_COOKIE[$key]);
    }
}
