<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;
class SlaPolicyRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->isAdmin() && $this->user()->is_active; }
    public function rules(): array { return [
        'name'=>['required','string','max:255'],'timezone'=>['required','timezone:all'],
        'hours'=>['required','array'],'hours.*'=>['array'],'hours.*.*'=>['array','size:2'],
        'hours.*.*.*'=>['required','date_format:H:i'],
        'exceptions'=>['nullable','array'],'exceptions.*'=>['array'],'exceptions.*.*'=>['array','size:2'],'exceptions.*.*.*'=>['required','date_format:H:i'],
        'rules'=>['required','array','size:4'],'rules.*.priority'=>['required',Rule::in(['low','medium','high','critical']),'distinct'],
        'rules.*.resolution_minutes'=>['required','integer','min:1','max:525600'],
        'rules.*.warning_percent'=>['required','integer','min:1','max:99'],
    ]; }
    public function after(): array { return [function (Validator $validator) {
        if ($validator->errors()->isNotEmpty()) { return; }
        $hasHours = false;
        foreach ($this->input('hours',[]) as $day=>$intervals) {
            if (! in_array((string) $day,['0','1','2','3','4','5','6'],true)) { $validator->errors()->add('hours','Weekday must be 0 through 6.'); }
            $hasHours = $hasHours || count($intervals) > 0;
            $this->validateIntervals($validator,$intervals,'hours.'.$day);
        }
        if (! $hasHours) { $validator->errors()->add('hours','At least one business interval is required.'); }
        foreach ($this->input('exceptions',[]) as $date=>$intervals) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d',(string) $date);
            if (! $parsed || $parsed->format('Y-m-d') !== (string) $date) { $validator->errors()->add('exceptions','Exception keys must be valid YYYY-MM-DD dates.'); }
            $this->validateIntervals($validator,$intervals,'exceptions.'.$date);
        }
    }]; }
    private function validateIntervals(Validator $validator, array $intervals, string $key): void {
        $previousEnd = null;
        foreach ($intervals as $interval) {
            if ($interval[0] >= $interval[1] || ($previousEnd !== null && $interval[0] < $previousEnd)) {
                $validator->errors()->add($key,'Intervals must be sorted, non-overlapping, and end after they start.');
            }
            $previousEnd = $interval[1];
        }
    }
}