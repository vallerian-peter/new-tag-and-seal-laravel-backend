<?php

namespace App\Mail;

use App\Models\ExtensionOfficer;
use App\Models\Farmer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ExtensionOfficerInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public ExtensionOfficer $extensionOfficer;
    public Farmer $farmer;
    public string $accessCode;
    public ?string $farmName;

    /**
     * Create a new message instance.
     */
    public function __construct(ExtensionOfficer $extensionOfficer, Farmer $farmer, string $accessCode, ?string $farmName = null)
    {
        $this->extensionOfficer = $extensionOfficer;
        $this->farmer = $farmer;
        $this->accessCode = $accessCode;
        $this->farmName = $farmName;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $subject = 'Farm Invitation - Tag and Seal';

        $farmerName = trim(($this->farmer->firstName ?? '') . ' ' . ($this->farmer->middleName ?? '') . ' ' . ($this->farmer->surname ?? ''));
        $farmerName = $farmerName ?: 'Farm Owner';
        
        $extensionOfficerName = trim(($this->extensionOfficer->firstName ?? '') . ' ' . ($this->extensionOfficer->middleName ?? '') . ' ' . ($this->extensionOfficer->lastName ?? ''));
        $extensionOfficerName = $extensionOfficerName ?: 'Extension Officer';

        $html = view('emails.extension_officer_invitation', [
            'extensionOfficer' => $this->extensionOfficer,
            'extensionOfficerName' => $extensionOfficerName,
            'farmer' => $this->farmer,
            'farmerName' => $farmerName,
            'accessCode' => $this->accessCode,
            'farmName' => $this->farmName,
        ])->render();

        return $this->subject($subject)
            ->html($html);
    }
}

