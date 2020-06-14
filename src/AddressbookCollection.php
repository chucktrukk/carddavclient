<?php

/*
 * CardDAV client library for PHP ("PHP-CardDavClient").
 *
 * Copyright (C) 2020 Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of PHP-CardDavClient.
 *
 * PHP-CardDavClient is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP-CardDavClient is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP-CardDavClient.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Objects of this class represent an addressbook collection on a WebDAV
 * server.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavClient;

use Sabre\VObject\UUIDUtil;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\XmlElements\ElementNames as XmlEN;

class AddressbookCollection extends WebDavCollection
{
    private const PROPNAMES = [
        XmlEN::DISPNAME,
        XmlEN::GETCTAG,
        XmlEN::SUPPORTED_ADDRDATA,
        XmlEN::ABOOK_DESC,
        XmlEN::MAX_RESSIZE,
        XmlEN::SUPPORTED_REPORT_SET
    ];

    public function getName(): string
    {
        return $this->props[XmlEN::DISPNAME] ?? basename($this->uri);
    }

    public function __toString(): string
    {
        $desc  = $this->getName() . " (" . $this->uri . ")";
        return $desc;
    }

    public function getDetails(): string
    {
        $desc  = "Addressbook " . $this->getName() . "\n";
        $desc .= "    URI: " . $this->uri . "\n";
        foreach ($this->props as $propName => $propVal) {
            $desc .= "    $propName: ";

            if (is_array($propVal)) {
                if (isset($propVal[0]) && is_array($propVal[0])) {
                    $propVal = array_map(
                        function (array $subarray): string {
                            return implode(" ", $subarray);
                        },
                        $propVal
                    );
                }
                $desc .= implode(", ", $propVal);
            } else {
                $desc .= $propVal;
            }

            $desc .= "\n";
        }

        return $desc;
    }

    public function supportsSyncCollection(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_SYNCCOLL);
    }

    public function supportsMultiGet(): bool
    {
        return $this->supportsReport(XmlEN::REPORT_MULTIGET);
    }

    public function getCTag(): ?string
    {
        return $this->props[XmlEN::GETCTAG] ?? null;
    }

    /**
     * Retrieves an address object from the addressbook collection and parses it to a VObject.
     *
     * @param $uri string
     *  URI of the address object to fetch
     * @return array
     *  Associative array with keys
     *   - etag(string): Entity tag of the returned card
     *   - vcf(string): VCard as string
     *   - vcard(VCard): VCard as Sabre/VObject VCard
     */
    public function getCard(string $uri): array
    {
        $client = $this->getClient();
        $response = $client->getAddressObject($uri);
        $response["vcard"] = \Sabre\VObject\Reader::read($response["vcf"]);
        return $response;
    }

    public function deleteCard(string $uri): void
    {
        $client = $this->getClient();
        $client->deleteResource($uri);
    }

    public function createCard(VCard $vcard): array
    {
        // Add UID if not present
        if (empty($vcard->select("UID"))) {
            $uuid = UUIDUtil::getUUID();
            Config::$logger->notice("Adding missing UID property to new VCard ($uuid)");
            $vcard->UID = $uuid;
        }

        // Assert validity of the Card for CardDAV, including valid UID property
        $this->validateCard($vcard);

        $client = $this->getClient();
        $newResInfo = $client->createResource(
            $vcard->serialize(),
            $client->absoluteUrl($vcard->UID . ".vcf")
        );

        return $newResInfo;
    }

    public function updateCard(string $uri, VCard $vcard, string $etag): ?string
    {
        // Assert validity of the Card for CardDAV, including valid UID property
        $this->validateCard($vcard);

        $client = $this->getClient();
        $etag = $client->updateResource($vcard->serialize(), $uri, $etag);

        return $etag;
    }

    protected function validateCard(VCard $vcard): void
    {
        $hasError = false;
        $errors = "";

        // Assert validity of the Card for CardDAV, including valid UID property
        $validityIssues = $vcard->validate(\Sabre\VObject\Node::PROFILE_CARDDAV | \Sabre\VObject\Node::REPAIR);
        foreach ($validityIssues as $issue) {
            $name = $issue["node"]->name;
            $msg = "Issue with $name of new VCard: " . $issue["message"];

            if ($issue["level"] <= 2) { // warning
                Config::$logger->warning($msg);
            } else { // error
                Config::$logger->error($msg);
                $errors .= "$msg\n";
                $hasError = true;
            }
        }

        if ($hasError) {
            Config::$logger->debug($vcard->serialize());
            throw new \InvalidArgumentException($errors);
        }
    }

    protected function getNeededCollectionPropertyNames(): array
    {
        $parentPropNames = parent::getNeededCollectionPropertyNames();
        $propNames = array_merge($parentPropNames, self::PROPNAMES);
        return array_unique($propNames);
    }

    protected function supportsReport(string $reportElement): bool
    {
        return in_array($reportElement, $this->props[XmlEN::SUPPORTED_REPORT_SET], true);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
