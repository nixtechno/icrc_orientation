<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** Builds the assessment question template and turns a filled-in copy into question data. */
class QuestionImport
{
    public const COLUMNS = ['type', 'question', 'option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'option_f', 'correct_answer', 'points'];

    /** Optional columns after the required ones; older templates without them still import. */
    public const EXTRA_COLUMNS = ['explanation'];

    public const MAX_ROWS = 300;

    /** Friendly names shown in the template, keyed by the stored question type. */
    public const TYPES = ['multiple_choice' => 'Multiple choice', 'multiple_answers' => 'Multiple answers', 'true_false' => 'True/False', 'short_answer' => 'Short answer', 'long_answer' => 'Long answer'];

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Questions');
        $sheet->fromArray([...self::COLUMNS, ...self::EXTRA_COLUMNS]);
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6EEE5');
        $sheet->freezePane('A2');
        foreach (['A' => 18, 'B' => 60, 'C' => 22, 'D' => 22, 'E' => 22, 'F' => 22, 'G' => 22, 'H' => 22, 'I' => 18, 'J' => 9, 'K' => 50] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('B:B')->getAlignment()->setWrapText(true);
        $sheet->getStyle('K:K')->getAlignment()->setWrapText(true);
        // A drop-down for the type column so people pick a type the import understands.
        $list = (new DataValidation)->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
            ->setShowErrorMessage(true)->setErrorTitle('Unknown type')->setError('Pick a type from the list.')
            ->setFormula1('"'.implode(',', self::TYPES).'"');
        $sheet->setDataValidation('A2:A'.(self::MAX_ROWS + 1), $list);

        $help = $book->createSheet()->setTitle('How to fill this in');
        $help->fromArray([
            ['How to fill in the Questions sheet'],
            ['Put one question per row on the Questions sheet. Leave the header row as it is. Only the Questions sheet is imported.'],
            [''],
            ['Column', 'What to enter'],
            ['type', 'Multiple choice, Multiple answers, True/False, Short answer or Long answer (pick from the drop-down).'],
            ['question', 'The question text.'],
            ['option_a … option_f', 'The answer choices for Multiple choice and Multiple answers (at least two). Leave blank for True/False and written answers.'],
            ['correct_answer', 'The letter of the right option (e.g. B), or its exact text. For Multiple answers, list two or more letters separated by commas (e.g. A, C). For True/False enter True or False. Leave blank for Short and Long answer; a facilitator marks those.'],
            ['points', 'Whole number from 1 to 100. Leave blank for 1 point.'],
            ['explanation', 'Optional. Why the correct answer is right. Staff see it beside their answer once their result is released.'],
            [''],
            ['Examples (copy the layout, not these rows)'],
            [...self::COLUMNS, ...self::EXTRA_COLUMNS],
            ['Multiple choice', 'What does ICRC stand for?', 'Infrastructure Concession Regulatory Commission', 'International Committee of the Red Cross', 'Industrial Credit Regulatory Council', '', '', '', 'A', 2, 'ICRC regulates infrastructure concessions in Nigeria.'],
            ['Multiple answers', 'Which of these are PPP models?', 'Build-Operate-Transfer', 'Sole proprietorship', 'Concession', 'Lease', '', '', 'A, C, D', 3],
            ['True/False', 'A concession agreement transfers ownership of public assets permanently.', '', '', '', '', '', '', 'False', 1],
            ['Short answer', 'Name one benefit of public-private partnerships.', '', '', '', '', '', '', '', 5],
        ], null, 'A1', true);
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $help->getStyle('A4:B4')->getFont()->setBold(true);
        $help->getStyle('A12')->getFont()->setBold(true);
        $help->getStyle('A13:K13')->getFont()->setBold(true);
        $help->getColumnDimension('A')->setWidth(22);
        $help->getColumnDimension('B')->setWidth(90);
        $help->getStyle('B5:B10')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Reads the Questions sheet. Returns the questions ready to save, or row-numbered problems if any row is wrong.
     *
     * @return array{questions: list<array>, errors: list<string>}
     */
    public function read(UploadedFile $file): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($file->getRealPath());
        $sheet = $book->getSheetByName('Questions') ?? $book->getSheet(0);
        $rows = $sheet->toArray(null, true, false, false);
        $book->disconnectWorksheets();

        $header = array_map(fn ($cell) => strtolower(trim(ltrim((string) $cell, "\xEF\xBB\xBF"))), array_shift($rows) ?? []);
        $explanationAt = array_search('explanation', array_slice($header, count(self::COLUMNS), null, true), true);
        $header = array_slice($header, 0, count(self::COLUMNS));
        if ($header !== self::COLUMNS) {
            return ['questions' => [], 'errors' => ['The first row must be the template headers: '.implode(', ', self::COLUMNS).'. Download the template and copy your questions into it.']];
        }
        $questions = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $values = array_map(fn ($cell) => $cell === null ? '' : trim((string) $cell), array_slice(array_pad($row, count(self::COLUMNS), null), 0, count(self::COLUMNS)));
            $explanation = $explanationAt === false ? '' : trim((string) ($row[$explanationAt] ?? ''));
            if (implode('', $values).$explanation === '') {
                continue;
            }
            if (count($questions) + count($errors) >= self::MAX_ROWS) {
                $errors[] = 'A file can hold up to '.self::MAX_ROWS.' questions. Split the rest into another file.';
                break;
            }
            $result = mb_strlen($explanation) > 5000 ? 'The explanation is longer than 5,000 characters.' : $this->question(array_combine(self::COLUMNS, $values));
            if (is_string($result)) {
                $errors[] = 'Row '.($i + 2).': '.$result;
            } else {
                $questions[] = [...$result, 'explanation' => $explanation !== '' ? $explanation : null];
            }
        }
        if (! $questions && ! $errors) {
            $errors[] = 'The Questions sheet has no questions under the header row.';
        }

        return ['questions' => $errors ? [] : $questions, 'errors' => $errors];
    }

    /** Checks one row and returns the question data, or what is wrong with it. */
    public function question(array $row): array|string
    {
        $type = $this->type($row['type']);
        if (! $type) {
            return $row['type'] === '' ? 'Choose a type.' : 'Unknown type "'.$row['type'].'". Use one of: '.implode(', ', self::TYPES).'.';
        }
        if ($row['question'] === '') {
            return 'The question is empty.';
        }
        if (mb_strlen($row['question']) > 10000) {
            return 'The question is longer than 10,000 characters.';
        }
        $points = $row['points'] === '' ? 1 : $row['points'];
        if (! preg_match('/^\d+(\.0+)?$/', (string) $points) || (int) $points < 1 || (int) $points > 100) {
            return 'Points must be a whole number from 1 to 100.';
        }
        $points = (int) $points;
        $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        // Options keep their letter so an answer like "B" can be matched.
        $lettered = [];
        foreach ($letters as $letter) {
            if (($text = $row['option_'.strtolower($letter)]) !== '') {
                $lettered[$letter] = $text;
            }
        }
        if (in_array($type, ['short_answer', 'long_answer'], true)) {
            return ['type' => $type, 'prompt' => $row['question'], 'options' => [], 'correct_answer' => null, 'points' => $points];
        }
        if ($type === 'true_false') {
            $answer = match (strtolower($row['correct_answer'])) { 'true', 't' => 'True', 'false', 'f' => 'False', default => null };
            if (! $answer) {
                return 'For True/False, the correct answer must be True or False.';
            }

            return ['type' => $type, 'prompt' => $row['question'], 'options' => ['True', 'False'], 'correct_answer' => $answer, 'points' => $points];
        }
        $options = array_values($lettered);
        if (count($options) < 2) {
            return 'Add at least two options (option_a, option_b, …).';
        }
        if (count(array_unique(array_map('mb_strtolower', $options))) !== count($options)) {
            return 'Two options have the same text.';
        }
        $pick = function (string $answer) use ($lettered) {
            $upper = strtoupper($answer);
            if (isset($lettered[$upper])) {
                return $lettered[$upper];
            }
            foreach ($lettered as $text) {
                if (mb_strtolower($text) === mb_strtolower($answer)) {
                    return $text;
                }
            }

            return null;
        };
        if ($type === 'multiple_choice') {
            if ($row['correct_answer'] === '') {
                return 'Enter the correct answer (a letter such as B, or the exact option text).';
            }
            $answer = $pick($row['correct_answer']);

            return $answer === null
                ? 'The correct answer "'.$row['correct_answer'].'" does not match any option. Use a letter (A–F) or the exact option text.'
                : ['type' => $type, 'prompt' => $row['question'], 'options' => $options, 'correct_answer' => $answer, 'points' => $points];
        }
        $answers = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $row['correct_answer'])), fn ($answer) => $answer !== ''));
        $correct = [];
        foreach ($answers as $answer) {
            if (($match = $pick($answer)) === null) {
                return 'The correct answer "'.$answer.'" does not match any option. Use letters (A–F) or exact option text, separated by commas.';
            }
            $correct[] = $match;
        }
        $correct = array_values(array_unique($correct));
        if (count($correct) < 2) {
            return 'Multiple answers needs at least two correct options, separated by commas (e.g. A, C).';
        }

        return ['type' => $type, 'prompt' => $row['question'], 'options' => $options, 'correct_answer' => json_encode($correct, JSON_THROW_ON_ERROR), 'points' => $points];
    }

    private function type(string $value): ?string
    {
        $key = preg_replace('/[^a-z]+/', '_', strtolower(trim($value)));

        return match (trim($key, '_')) {
            'multiple_choice', 'mcq', 'single_choice' => 'multiple_choice',
            'multiple_answers', 'multiple_answer', 'multiple_choice_multiple_answers', 'multi_select' => 'multiple_answers',
            'true_false', 'truefalse', 'tf' => 'true_false',
            'short_answer', 'short' => 'short_answer',
            'long_answer', 'long', 'essay' => 'long_answer',
            default => null,
        };
    }
}
