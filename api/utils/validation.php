<?php

// 생년월일 문자열(YYYY-MM-DD)이 형식에 맞고, 만 10세 이상인지 검증한다.
// 앱의 DatePicker가 -10년/-100년으로 범위를 막아주지만, API를 직접 호출하는
// 우회를 막기 위해 서버에서도 동일한 규칙을 적용한다.
function isValidBirthday($birthday) {
    $date = DateTime::createFromFormat('Y-m-d', $birthday);

    if (!$date || $date->format('Y-m-d') !== $birthday) {
        return false;
    }

    $today = new DateTime('today');

    if ($date > $today) {
        return false;
    }

    $minBirthday = (clone $today)->modify('-10 years');

    if ($date > $minBirthday) {
        return false;
    }

    return true;
}
?>
