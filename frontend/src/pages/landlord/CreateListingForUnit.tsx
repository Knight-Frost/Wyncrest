/**
 * Thin wrapper around CreateListing for the unit-scoped entry point
 * (/app/properties/:propertyId/units/:unitId/listings/new). Reuses the same
 * wizard — never a second implementation — just pre-selects the unit.
 */
import { useParams } from 'react-router';
import { CreateListing } from './CreateListing';

export function CreateListingForUnit() {
  const { propertyId, unitId } = useParams();
  return (
    <CreateListing
      initialPropertyId={propertyId ? Number(propertyId) : undefined}
      initialUnitId={unitId ? Number(unitId) : undefined}
    />
  );
}
